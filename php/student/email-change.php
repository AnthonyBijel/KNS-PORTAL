<?php
require_once __DIR__ . '/settings-common.php';

final class KnsStudentEmailChange
{
    private Closure $deliver;
    public function __construct(private PDO $pdo, ?callable $deliver = null)
    {
        $this->deliver = $deliver ? Closure::fromCallable($deliver) : knsStudentSettingsMailer();
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function send(array $session, array $input): array
    {
        $email = knsStudentSettingsText($input, 'new_email', 254);
        $confirmation = knsStudentSettingsText($input, 'email_confirmation', 254);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new KnsStudentSettingsError('Enter a valid new email address.');
        if ($email !== $confirmation) throw new KnsStudentSettingsError('The new email addresses must match.');
        $code = (string) random_int(100000, 999999);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $this->pdo->beginTransaction();
        try {
            $account = knsStudentSettingsAccount($this->pdo, $session, true);
            $id = (int) $account['id'];
            if (!knsStudentPreferences($this->pdo, $id)['installed']) throw new KnsStudentSettingsError('Apply 20261107_student_settings.sql before changing email.', 503);
            if (strcasecmp($email, $account['email'] ?? '') === 0) throw new KnsStudentSettingsError('Enter a different email address.');
            $duplicate = $this->pdo->prepare('SELECT id FROM students WHERE email=? AND id<>? LIMIT 1');
            $duplicate->execute([$email, $id]);
            if ($duplicate->fetchColumn() !== false) throw new KnsStudentSettingsError('This email address is already in use.', 409);
            $statement = $this->pdo->prepare('SELECT created_at FROM student_email_changes WHERE student_id=? FOR UPDATE');
            $statement->execute([$id]); $last = $statement->fetchColumn();
            if ($last !== false && $last > gmdate('Y-m-d H:i:s', time()-60)) throw new KnsStudentSettingsError('Wait 60 seconds before requesting another verification code.', 429);
            $statement = $this->pdo->prepare('INSERT INTO student_email_changes(student_id,new_email,previous_email,code_hash,attempts,expires_at,created_at)
                VALUES (?,?,?,?,0,?,?) ON DUPLICATE KEY UPDATE new_email=VALUES(new_email),previous_email=VALUES(previous_email),
                code_hash=VALUES(code_hash),attempts=0,expires_at=VALUES(expires_at),created_at=VALUES(created_at)');
            $statement->execute([$id, $email, $account['email'], $hash, gmdate('Y-m-d H:i:s',time()+600), gmdate('Y-m-d H:i:s')]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        try {
            ($this->deliver)($email, 'KNS Portal email verification code',
                "Your KNS Portal email verification code is $code. It expires in 10 minutes. Your current email stays unchanged until you verify this code. Do not share it.");
        } catch (Throwable $error) {
            $statement = $this->pdo->prepare('DELETE FROM student_email_changes WHERE student_id=? AND code_hash=?');
            $statement->execute([$id, $hash]);
            error_log('Student email verification delivery failed (' . get_class($error) . ').');
            throw new KnsStudentSettingsError('Unable to send a verification code. Your current email has not changed. Please check mail delivery and try again.', 503);
        }
        return ['state'=>(new KnsStudentSettings($this->pdo, $this->deliver))->state($session),
            'message'=>'A verification code was sent to your new email. Enter it below to finish.'];
    }

    public function verify(array $session, array $input): array
    {
        $code = knsStudentSettingsText($input, 'verification_code', 6);
        if (!preg_match('/^[0-9]{6}$/D', $code)) throw new KnsStudentSettingsError('Enter the six-digit verification code.');
        $this->pdo->beginTransaction();
        try {
            $account = knsStudentSettingsAccount($this->pdo, $session, true);
            $id = (int) $account['id'];
            $statement = $this->pdo->prepare('SELECT * FROM student_email_changes WHERE student_id=? FOR UPDATE');
            $statement->execute([$id]); $challenge = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$challenge || $challenge['expires_at'] <= gmdate('Y-m-d H:i:s') || (int) $challenge['attempts'] >= 5
                || (string) $challenge['previous_email'] !== (string) $account['email']) {
                throw new KnsStudentSettingsError('This code expired or was already used. Request a new verification code.', 410);
            }
            if (!password_verify($code, $challenge['code_hash'])) {
                $attempts = (int) $challenge['attempts'] + 1;
                $statement = $this->pdo->prepare('UPDATE student_email_changes SET attempts=?, expires_at=? WHERE student_id=?');
                $statement->execute([$attempts, $attempts >= 5 ? gmdate('Y-m-d H:i:s') : $challenge['expires_at'], $id]);
                $this->pdo->commit();
                throw new KnsStudentSettingsError($attempts >= 5 ? 'Too many incorrect codes. Request a new code.' : 'The verification code is incorrect.', $attempts >= 5 ? 429 : 422);
            }
            $duplicate = $this->pdo->prepare('SELECT id FROM students WHERE email=? AND id<>? LIMIT 1 FOR UPDATE');
            $duplicate->execute([$challenge['new_email'], $id]);
            if ($duplicate->fetchColumn() !== false) throw new KnsStudentSettingsError('This email address is already in use.', 409);
            $statement = $this->pdo->prepare('UPDATE students SET email=? WHERE id=?');
            $statement->execute([$challenge['new_email'], $id]);
            $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE student_id=?')->execute([$id]);
            $statement = $this->pdo->prepare('DELETE FROM student_email_changes WHERE student_id=?'); $statement->execute([$id]);
            knsStudentActivity($this->pdo, $id, 'Your email address was verified and updated.');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) throw new KnsStudentSettingsError('This email address is already in use.', 409);
            throw $error;
        }
        $settings = new KnsStudentSettings($this->pdo, $this->deliver);
        $state = $settings->state($session);
        $previous = $state; $previous['profile']['email'] = $account['email'];
        return ['state'=>$state, 'message'=>'Your new email was verified and saved.',
            'notice'=>$settings->notice($previous, 'Your KNS Portal email address was changed after verification.')];
    }
}
