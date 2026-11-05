<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/password_reset_common.php';
$pdo->exec("SET time_zone = '+00:00'");
foreach (['students', 'password_reset_tokens', 'password_reset_rate_limits'] as $table) {
    $definition = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition, 1);
    $pdo->exec($definition);
}
$pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/20261105_student_account_setup.sql'));
$insert = $pdo->prepare(
    'INSERT INTO students (student_number, password_hash, first_name, last_name, course, year_level, section, status, email, contact_number)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
for ($index = 1; $index <= 8; $index++) {
    $insert->execute([
        'RESET-TEST-' . $index, password_hash('Original-test-password!', PASSWORD_DEFAULT),
        'Recovery', 'Test', 'BSCS', 1, 'TEST', $index === 8 ? 'Inactive' : 'Active',
        'recovery@example.invalid', '09171234567',
    ]);
}
$sent = [];
$service = new KnsPasswordResetService($pdo, function (string $channel, string $to, string $otp) use (&$sent): void {
    $sent[] = compact('channel', 'to', 'otp');
});
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $action, int $status, string $message): void
{
    try {
        $action();
    } catch (KnsPasswordResetError $error) {
        check($error->status === $status, $message . ': wrong error ' . $error->status);
        return;
    }
    throw new RuntimeException($message . ': request unexpectedly succeeded');
}
function tokenRow(PDO $pdo, array $session): array
{
    $statement = $pdo->prepare('SELECT * FROM password_reset_tokens WHERE id = ?');
    $statement->execute([$session['password_reset_otp']['token_id']]);
    return $statement->fetch(PDO::FETCH_ASSOC);
}
function startRecovery(KnsPasswordResetService $service, string $number, array &$session, string $ip, string $channel = 'email'): void
{
    $service->lookup($number, $session, $ip);
    $service->request($number, $channel, $session, $ip);
}

check(knsResetPhone('0917 123 4567') === '+639171234567', 'Philippine phone normalization');
check(knsResetPhone('+1 (202) 555-0123') === '+12025550123', 'International phone normalization');
check(knsResetPhone('javascript:12345678') === '', 'Invalid phone accepted');
check(knsResetMask('email', 'student@example.invalid') === 's***@example.invalid', 'Email masking');
check(knsResetMask('sms', '+639171234567') === '*********4567', 'Phone masking');
$session = [];
$lookup = $service->lookup('RESET-TEST-1', $session, 'test-ip-1');
check(!str_contains(json_encode($lookup), 'recovery@example.invalid'), 'Unmasked email returned');
check(!str_contains(json_encode($lookup), '09171234567'), 'Unmasked phone returned');
$result = $service->request('RESET-TEST-1', 'email', $session, 'test-ip-1');
$row = tokenRow($pdo, $session);
$otp = $sent[array_key_last($sent)]['otp'];
check(preg_match('/^[0-9]{6}$/', $otp) === 1, 'OTP is not six digits');
check($row['token_hash'] !== $otp && password_verify($otp, $row['token_hash']), 'OTP not hash-only');
check(strtotime($row['expires_at'] . ' UTC') - time() <= 600, 'OTP expiry exceeded ten minutes');
check(strtotime($row['expires_at'] . ' UTC') - time() >= 595, 'OTP expiry too short');
check(!str_contains(json_encode($result), $otp), 'OTP leaked in send response');
$emptySession = [];
rejects(fn() => $service->reset('New-test-password!', 'New-test-password!', $emptySession, 'no-proof'), 410, 'Reset without verification');
$otherSession = [];
rejects(fn() => $service->verify($otp, $otherSession, 'other-browser'), 410, 'OTP from another browser');
rejects(fn() => $service->request('RESET-TEST-1', 'sms', $session, 'test-ip-1'), 429, 'Resend cooldown');
$secondBrowser = [];
$service->lookup('RESET-TEST-1', $secondBrowser, 'test-ip-2');
rejects(fn() => $service->request('RESET-TEST-1', 'email', $secondBrowser, 'test-ip-2'), 429, 'Cross-browser cooldown');
$service->verify($otp, $session, 'test-ip-1');
check(!isset($session['password_reset_otp']) && isset($session['password_reset_grant']), 'Verification grant missing');
$grantSession = $session;
$usedContext = ['password_reset_otp' => ['student_id' => (int) $row['student_id'], 'token_id' => (int) $row['id'], 'expires' => time() + 600]];
rejects(fn() => $service->verify($otp, $usedContext, 'replay-browser'), 410, 'OTP replay');
rejects(fn() => $service->reset('New-test-password!', 'Different-password!', $session, 'test-ip-1'), 422, 'Password mismatch');
rejects(fn() => $service->reset('short', 'short', $session, 'test-ip-1'), 422, 'Weak password');
$service->reset('New-test-password!', 'New-test-password!', $session, 'test-ip-1');
$updated = $pdo->query("SELECT password_hash FROM students WHERE student_number = 'RESET-TEST-1'")->fetchColumn();
check(password_verify('New-test-password!', $updated), 'New password not saved');
check(!password_verify('Original-test-password!', $updated), 'Old password still works');
check(!isset($session['password_reset_grant']), 'Grant not cleared');
rejects(fn() => $service->reset('Another-password!', 'Another-password!', $grantSession, 'grant-replay'), 410, 'Grant replay');

$session = [];
startRecovery($service, 'RESET-TEST-2', $session, 'attempt-ip');
$otp = $sent[array_key_last($sent)]['otp'];
$wrong = $otp === '111111' ? '222222' : '111111';
for ($attempt = 1; $attempt <= 5; $attempt++) {
    rejects(fn() => $service->verify($wrong, $session, 'attempt-ip'), $attempt === 5 ? 429 : 422, 'Wrong OTP attempt ' . $attempt);
}
check((int) tokenRow($pdo, $session)['attempts'] === 5, 'Attempt count did not persist');
rejects(fn() => $service->verify($otp, $session, 'attempt-ip'), 410, 'Locked code accepted');

$session = [];
startRecovery($service, 'RESET-TEST-3', $session, 'expire-ip', 'sms');
check($sent[array_key_last($sent)]['to'] === '+639171234567', 'SMS used unsaved/un-normalized destination');
$otp = $sent[array_key_last($sent)]['otp'];
$pdo->exec('UPDATE password_reset_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ' . (int) $session['password_reset_otp']['token_id']);
rejects(fn() => $service->verify($otp, $session, 'expire-ip'), 410, 'Expired OTP accepted');

$session = [];
startRecovery($service, 'RESET-TEST-4', $session, 'resend-ip');
$oldContext = $session;
$oldOtp = $sent[array_key_last($sent)]['otp'];
$pdo->exec('UPDATE password_reset_tokens SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 SECOND) WHERE student_id = ' . (int) $session['password_reset_lookup']['student_id']);
$service->request('RESET-TEST-4', 'sms', $session, 'resend-ip');
rejects(fn() => $service->verify($oldOtp, $oldContext, 'resend-replay'), 410, 'Previous OTP survived resend');
$service->verify($sent[array_key_last($sent)]['otp'], $session, 'resend-ip');
$session['password_reset_grant']['expires'] = time() - 1;
rejects(fn() => $service->reset('New-test-password!', 'New-test-password!', $session, 'resend-ip'), 410, 'Expired verification grant accepted');

$session = [];
startRecovery($service, 'RESET-TEST-5', $session, 'hour-ip');
for ($send = 2; $send <= 5; $send++) {
    $pdo->exec('UPDATE password_reset_tokens SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 SECOND) WHERE student_id = ' . (int) $session['password_reset_lookup']['student_id']);
    $service->request('RESET-TEST-5', 'email', $session, 'hour-ip');
}
$pdo->exec('UPDATE password_reset_tokens SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 SECOND) WHERE student_id = ' . (int) $session['password_reset_lookup']['student_id']);
rejects(fn() => $service->request('RESET-TEST-5', 'sms', $session, 'hour-ip'), 429, 'Hourly per-student limit');

$failing = new KnsPasswordResetService($pdo, function (): void { throw new RuntimeException('Fake provider failure'); });
$session = [];
$failing->lookup('RESET-TEST-6', $session, 'delivery-ip');
rejects(fn() => $failing->request('RESET-TEST-6', 'email', $session, 'delivery-ip'), 502, 'Provider failure not reported');
check(!isset($session['password_reset_otp']), 'Failed provider left usable session');
$failedRow = $pdo->query("SELECT * FROM password_reset_tokens WHERE student_id = (SELECT id FROM students WHERE student_number = 'RESET-TEST-6') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check($failedRow['used_at'] !== null && strtotime($failedRow['expires_at'] . ' UTC') <= time(), 'Failed delivery left live OTP');
$session = [];
rejects(fn() => $service->lookup('UNKNOWN-STUDENT', $session, 'lookup-ip'), 422, 'Unknown student');
rejects(fn() => $service->lookup('RESET-TEST-8', $session, 'lookup-ip'), 422, 'Inactive student');
for ($hit = 1; $hit <= 30; $hit++) {
    $service->lookup('RESET-TEST-7', $session, 'rate-ip');
}
rejects(fn() => $service->lookup('RESET-TEST-7', $session, 'rate-ip'), 429, 'IP lookup limit');


// Provider setup failures must not create codes or consume per-student send limits.
$environmentNames = [
    'KNS_PASSWORD_RESET_CONFIG', 'KNS_SMTP_USERNAME', 'KNS_SMTP_PASSWORD', 'KNS_SMTP_FROM_EMAIL',
    'KNS_TWILIO_ACCOUNT_SID', 'KNS_TWILIO_AUTH_TOKEN', 'KNS_TWILIO_FROM_NUMBER',
];
$previousEnvironment = [];
foreach ($environmentNames as $name) {
    $previousEnvironment[$name] = getenv($name);
    putenv($name);
}
$privateFixture = tempnam(sys_get_temp_dir(), 'kns-reset-config-');
try {
    file_put_contents($privateFixture, "<?php return [];\n");
    putenv('KNS_PASSWORD_RESET_CONFIG=' . $privateFixture);
    $unconfigured = new KnsPasswordResetService($pdo);
    $session = [];
    $unconfigured->lookup('RESET-TEST-7', $session, 'config-ip');
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn();
    rejects(fn() => $unconfigured->request('RESET-TEST-7', 'email', $session, 'config-ip'), 503, 'Missing Gmail configuration');
    rejects(fn() => $unconfigured->request('RESET-TEST-7', 'sms', $session, 'config-ip'), 503, 'Missing Twilio configuration');
    check((int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn() === $beforeCount, 'Missing config consumed student OTP limit');
    check(!isset($session['password_reset_otp']), 'Missing config created a recovery code');

    file_put_contents($privateFixture, "<?php return ['smtp_username' => 'sender@example.invalid', 'smtp_password' => 'abcd efgh ijkl mnop'];\n");
    $config = require __DIR__ . '/../config/password-reset.php';
    check($config['smtp_username'] === 'sender@example.invalid', 'Private config not loaded');
    check($config['smtp_from'] === 'sender@example.invalid', 'Sender address fallback failed');
    check($config['smtp_password'] === 'abcdefghijklmnop', 'App password spaces were not normalized');
    putenv('KNS_SMTP_USERNAME=override@example.invalid');
    $config = require __DIR__ . '/../config/password-reset.php';
    check($config['smtp_username'] === 'override@example.invalid', 'Environment did not override private config');

    putenv('KNS_PASSWORD_RESET_CONFIG=' . __FILE__);
    try {
        require __DIR__ . '/../config/password-reset.php';
        throw new LogicException('Accepted public credential file');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'Recovery credentials must be outside the public directory.', 'Public credential path was not rejected');
    }
} finally {
    unlink($privateFixture);
    foreach ($previousEnvironment as $name => $value) {
        if ($value === false) putenv($name);
        else putenv($name . '=' . $value);
    }
}

echo "PASS: $checks recovery checks; all fixtures used temporary tables; no real messages sent.", PHP_EOL;