<?php
require_once __DIR__ . '/student-password-policy.php';
require_once __DIR__ . '/../student-term-enrollments.php';

final class KnsPasswordResetError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422, public array $details = [])
    {
        parent::__construct($message);
    }
}

function knsResetPhone(?string $phone): string
{
    $phone = preg_replace('/[\s().-]+/', '', trim($phone ?? ''));
    if (preg_match('/^09[0-9]{9}$/', $phone)) {
        $phone = '+63' . substr($phone, 1);
    } elseif (preg_match('/^9[0-9]{9}$/', $phone)) {
        $phone = '+63' . $phone;
    } elseif (preg_match('/^63[0-9]{10}$/', $phone)) {
        $phone = '+' . $phone;
    }
    return preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) ? $phone : '';
}

function knsResetContacts(array $student): array
{
    $email = trim((string) ($student['email'] ?? ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    $phone = knsResetPhone($student['contact_number'] ?? '');
    return ['email' => $email, 'sms' => $phone];
}

function knsResetMask(string $channel, string $destination): string
{
    if ($destination === '') return '';
    if ($channel === 'sms') return str_repeat('*', max(4, strlen($destination) - 4)) . substr($destination, -4);
    [$local, $domain] = explode('@', $destination, 2);
    return mb_substr($local, 0, 1, 'UTF-8') . '***@' . $domain;
}

function knsResetDeliveryConfig(string $channel): array
{
    $config = require __DIR__ . '/../../config/password-reset.php';
    if ($channel === 'email') {
        if (!filter_var($config['smtp_username'], FILTER_VALIDATE_EMAIL)
            || $config['smtp_password'] === '' || !filter_var($config['smtp_from'], FILTER_VALIDATE_EMAIL)) {
            throw new KnsPasswordResetError('Email recovery is not configured. Contact Student Services or try SMS.', 503);
        }
    } elseif ($channel === 'sms') {
        if (!preg_match('/^AC[a-f0-9]{32}$/i', $config['twilio_sid'])
            || $config['twilio_token'] === '' || knsResetPhone($config['twilio_from']) === '') {
            throw new KnsPasswordResetError('SMS recovery is not configured. Contact Student Services or try Email.', 503);
        }
    } else {
        throw new KnsPasswordResetError('Choose Email or SMS.');
    }
    return $config;
}

function knsResetDeliver(string $channel, string $destination, string $otp): void
{
    $message = "Your KNS Portal password reset code is $otp. It expires in 10 minutes. Do not share this code.";
    knsDeliverMessage($channel, $destination, 'KNS Portal password reset code', $message);
}

// Recovery and account setup share the exact sender, PHPMailer transport, and TLS settings.
function knsDeliverMessage(string $channel, string $destination, string $subject, string $message): void
{
    $config = knsResetDeliveryConfig($channel);
    if ($channel === 'email') {
        require_once __DIR__ . '/../../vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->Port = 587;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];
        $mail->Timeout = 15;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['smtp_from'], 'KNS Portal');
        $mail->addAddress($destination);
        $mail->Subject = $subject;
        $mail->Body = $message;
        if (!$mail->send()) throw new RuntimeException('Email delivery failed.');
        return;
    }

    $curl = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . $config['twilio_sid'] . '/Messages.json');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $destination,
            'From' => knsResetPhone($config['twilio_from']),
            'Body' => $message,
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_USERPWD => $config['twilio_sid'] . ':' . $config['twilio_token'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $result = is_string($response) ? json_decode($response, true) : null;
    if ($status !== 201 || !is_array($result) || empty($result['sid'])) {
        throw new RuntimeException('SMS delivery failed.');
    }
}

final class KnsPasswordResetService
{
    private Closure $deliver;
    private bool $usesDefaultDelivery;

    public function __construct(private PDO $pdo, ?callable $deliver = null)
    {
        $this->deliver = Closure::fromCallable($deliver ?? 'knsResetDeliver');
        $this->usesDefaultDelivery = $deliver === null;
    }

    private function rateLimit(string $scope, string $key, int $limit, int $seconds): void
    {
        $bucket = intdiv(time(), $seconds);
        $hash = hash('sha256', $scope . '|' . $key . '|' . $bucket);
        $expires = ($bucket + 1) * $seconds;
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_rate_limits (scope_hash, hits, expires_at) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = LEAST(hits + 1, 65535)'
        );
        $stmt->execute([$hash, gmdate('Y-m-d H:i:s', $expires)]);
        $stmt = $this->pdo->prepare('SELECT hits FROM password_reset_rate_limits WHERE scope_hash = ?');
        $stmt->execute([$hash]);
        if ((int) $stmt->fetchColumn() > $limit) {
            throw new KnsPasswordResetError('Too many recovery requests. Please try again later.', 429, [
                'retry_after' => max(1, $expires - time()),
            ]);
        }
    }

    private function studentByNumber(string $number, bool $lock = false): array
    {
        knsSyncStudentTermByNumber($this->pdo, $number);
        $stmt = $this->pdo->prepare(
            'SELECT id, student_number, status, email, contact_number FROM students WHERE student_number = ? LIMIT 1'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$number]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student || $student['status'] !== 'Active') {
            throw new KnsPasswordResetError('Recovery is unavailable for these student details. Contact Student Services.');
        }
        return $student;
    }

    private function lockStudent(int $id): void
    {
        knsSyncStudentTerm($this->pdo, $id);
        $stmt = $this->pdo->prepare('SELECT status FROM students WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== 'Active') {
            throw new KnsPasswordResetError('Recovery is unavailable for these student details. Contact Student Services.');
        }
    }

    private function token(int $id, int $studentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *, expires_at > UTC_TIMESTAMP() AS is_live
             FROM password_reset_tokens WHERE id = ? AND student_id = ? FOR UPDATE'
        );
        $stmt->execute([$id, $studentId]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$token || !(int) $token['is_live']) {
            throw new KnsPasswordResetError('This code has expired. Request a new code.', 410);
        }
        return $token;
    }

    public function lookup(string $number, array &$session, string $ip): array
    {
        $this->rateLimit('lookup-ip', $ip, 30, 900);
        $student = $this->studentByNumber($number);
        $contacts = knsResetContacts($student);
        if ($contacts['email'] === '' && $contacts['sms'] === '') {
            throw new KnsPasswordResetError('Recovery is unavailable for these student details. Contact Student Services.');
        }
        unset($session['password_reset_otp'], $session['password_reset_grant']);
        $session['password_reset_lookup'] = [
            'student_id' => (int) $student['id'], 'student_number' => $number, 'expires' => time() + 900,
        ];
        $channels = [];
        foreach ($contacts as $channel => $destination) {
            $channels[$channel] = ['available' => $destination !== '', 'masked' => knsResetMask($channel, $destination)];
        }
        return ['message' => 'Choose where to receive your recovery code.', 'channels' => $channels];
    }

    public function request(string $number, string $channel, array &$session, string $ip): array
    {
        $this->rateLimit('send-ip', $ip, 15, 3600);
        $lookup = $session['password_reset_lookup'] ?? [];
        if (($lookup['student_number'] ?? '') !== $number || ($lookup['expires'] ?? 0) <= time()) {
            throw new KnsPasswordResetError('Look up your student number again before requesting a code.', 409);
        }
        if (!in_array($channel, ['email', 'sms'], true)) {
            throw new KnsPasswordResetError('Choose Email or SMS.');
        }

        if ($this->usesDefaultDelivery) knsResetDeliveryConfig($channel);

        $this->pdo->beginTransaction();
        try {
            // The student lock serializes sends and resets across every browser/session.
            $student = $this->studentByNumber($number, true);
            $destination = knsResetContacts($student)[$channel];
            if ($destination === '') {
                throw new KnsPasswordResetError('No valid saved contact is available for this option. Choose another option.');
            }
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS sent, MAX(UNIX_TIMESTAMP(created_at)) AS last_sent
                 FROM password_reset_tokens WHERE student_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
            );
            $stmt->execute([$student['id']]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $history['sent'] >= 5) {
                throw new KnsPasswordResetError('You have reached the limit of five codes per hour. Please try again later.', 429, ['retry_after' => 3600]);
            }
            $wait = 60 - (time() - (int) ($history['last_sent'] ?? 0));
            if ($wait > 0) {
                throw new KnsPasswordResetError('Please wait before requesting another code.', 429, ['retry_after' => $wait]);
            }

            $otp = (string) random_int(100000, 999999);
            $hash = password_hash($otp, PASSWORD_DEFAULT);
            $expires = time() + 600;
            $stmt = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = COALESCE(used_at, UTC_TIMESTAMP()),
                 expires_at = LEAST(expires_at, UTC_TIMESTAMP()) WHERE student_id = ?'
            );
            $stmt->execute([$student['id']]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO password_reset_tokens (student_id, token_hash, channel, expires_at)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$student['id'], $hash, $channel, gmdate('Y-m-d H:i:s', $expires)]);
            $tokenId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        unset($session['password_reset_otp'], $session['password_reset_grant']);
        try {
            // Never hold a database transaction open while calling a delivery provider.
            ($this->deliver)($channel, $destination, $otp);
        } catch (Throwable $error) {
            $stmt = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP(), expires_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$tokenId]);
            error_log('Password reset delivery failed (' . get_class($error) . ').');
            if ($error instanceof KnsPasswordResetError) throw $error;
            throw new KnsPasswordResetError('Unable to send the recovery code. Please try again later or choose another option.', 502);
        } finally {
            unset($otp);
        }
        $session['password_reset_otp'] = [
            'token_id' => $tokenId, 'student_id' => (int) $student['id'], 'expires' => $expires,
        ];
        return [
            'message' => 'A recovery code was sent to ' . knsResetMask($channel, $destination) . '.',
            'channel' => $channel, 'masked' => knsResetMask($channel, $destination),
            'expires_in' => max(0, $expires - time()), 'resend_after' => 60,
        ];
    }

    public function verify(string $otp, array &$session, string $ip): array
    {
        $this->rateLimit('verify-ip', $ip, 60, 900);
        if (!preg_match('/^[0-9]{6}$/', $otp)) {
            throw new KnsPasswordResetError('Enter the six-digit recovery code.');
        }
        $context = $session['password_reset_otp'] ?? [];
        if (empty($context['token_id']) || ($context['expires'] ?? 0) <= time()) {
            throw new KnsPasswordResetError('This recovery session has expired. Request a new code.', 410);
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockStudent((int) $context['student_id']);
            $token = $this->token((int) $context['token_id'], (int) $context['student_id']);
            if ($token['used_at'] !== null || (int) $token['attempts'] >= 5) {
                throw new KnsPasswordResetError('This code can no longer be used. Request a new code.', 410);
            }
            if (!password_verify($otp, $token['token_hash'])) {
                $attempts = (int) $token['attempts'] + 1;
                $stmt = $this->pdo->prepare(
                    'UPDATE password_reset_tokens SET attempts = ?,
                     used_at = CASE WHEN ? >= 5 THEN UTC_TIMESTAMP() ELSE used_at END WHERE id = ?'
                );
                $stmt->execute([$attempts, $attempts, $token['id']]);
                $this->pdo->commit();
                throw new KnsPasswordResetError(
                    $attempts >= 5 ? 'Too many incorrect codes. Request a new code.' : 'Incorrect recovery code. Please try again.',
                    $attempts >= 5 ? 429 : 422, ['attempts_remaining' => max(0, 5 - $attempts)]
                );
            }
            $stmt = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?');
            $stmt->execute([$token['id']]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        $session['password_reset_grant'] = [
            'token_id' => (int) $token['id'], 'student_id' => (int) $token['student_id'],
            'expires' => min((int) $context['expires'], time() + 300),
        ];
        unset($session['password_reset_otp']);
        return ['message' => 'Code verified. Choose your new password.', 'expires_in' => $session['password_reset_grant']['expires'] - time()];
    }

    public function reset(string $password, string $confirmation, array &$session, string $ip): array
    {
        $this->rateLimit('reset-ip', $ip, 15, 900);
        $grant = $session['password_reset_grant'] ?? [];
        if (empty($grant['token_id']) || ($grant['expires'] ?? 0) <= time()) {
            throw new KnsPasswordResetError('Verify a new recovery code before changing your password.', 410);
        }
        try {
            knsValidateStudentPassword($password, $confirmation);
        } catch (InvalidArgumentException $error) {
            throw new KnsPasswordResetError($error->getMessage());
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->pdo->beginTransaction();
        try {
            $this->lockStudent((int) $grant['student_id']);
            $token = $this->token((int) $grant['token_id'], (int) $grant['student_id']);
            if ($token['used_at'] === null) {
                throw new KnsPasswordResetError('Verify your recovery code first.', 403);
            }
            $stmt = $this->pdo->prepare('UPDATE students SET password_hash = ?, requires_password_change = 0, temporary_password_expires_at = NULL WHERE id = ?');
            $stmt->execute([$hash, $grant['student_id']]);
            $stmt = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = COALESCE(used_at, UTC_TIMESTAMP()),
                 expires_at = LEAST(expires_at, UTC_TIMESTAMP()) WHERE student_id = ?'
            );
            $stmt->execute([$grant['student_id']]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        unset($session['password_reset_lookup'], $session['password_reset_otp'], $session['password_reset_grant']);
        return ['message' => 'Your password has been updated. Sign in with your new password.'];
    }
}

function knsResetInput(array $data, string $name, int $maxLength = 120): string
{
    $value = $data[$name] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $maxLength) {
        throw new KnsPasswordResetError('Enter valid recovery details.');
    }
    return $value;
}

function knsResetStudentNumber(array $data): string
{
    $number = trim(knsResetInput($data, 'student_number'));
    if ($number === '' || mb_strlen($number, 'UTF-8') > 30) {
        throw new KnsPasswordResetError('Enter a valid student number.');
    }
    return $number;
}

function knsPasswordResetEndpoint(callable $handler): void
{
    require __DIR__ . '/session.php';
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
            throw new KnsPasswordResetError('Use POST for password recovery.', 405);
        }
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            throw new KnsPasswordResetError('Send recovery details as JSON.', 415);
        }
        $body = file_get_contents('php://input', false, null, 0, 4097);
        if (strlen($body) > 4096) throw new KnsPasswordResetError('The recovery request is too large.', 413);
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new KnsPasswordResetError('The recovery request is invalid.', 400);
        }
        if (!is_array($data)) throw new KnsPasswordResetError('The recovery request is invalid.', 400);
        $csrf = $data['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
            throw new KnsPasswordResetError('Your session expired. Reload the login page and try again.', 403);
        }
        define('KNS_PASSWORD_RESET_REQUEST', true);
        require __DIR__ . '/../../db.php';
        $pdo->exec("SET time_zone = '+00:00'");
        $service = new KnsPasswordResetService($pdo);
        $result = $handler($service, $data, $_SESSION, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (KnsPasswordResetError $error) {
        http_response_code($error->status);
        if (isset($error->details['retry_after'])) header('Retry-After: ' . (int) $error->details['retry_after']);
        echo json_encode(['ok' => false, 'message' => $error->getMessage()] + $error->details, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        error_log('Password reset request failed (' . get_class($error) . ').');
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Password recovery is temporarily unavailable. Please contact Student Services.']);
    }
}