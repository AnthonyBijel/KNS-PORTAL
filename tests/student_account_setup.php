<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('KNS_TERM_EMAIL_DEFER', true);
require __DIR__ . '/../db.php';
require __DIR__ . '/../php/auth/student-account-setup.php';
require __DIR__ . '/../vendor/autoload.php';
// All writes, including enrollment snapshots and migrations, use this disposable database.
$source = $pdo;
$database = 'kns_setup_unit_' . bin2hex(random_bytes(6));
$source->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
$pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['students', 'password_reset_tokens', 'password_reset_rate_limits'] as $table) {
    $pdo->exec($source->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1]);
}
$pdo->exec("SET time_zone = '+00:00'");
$pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/20261105_academic_term_management.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/20261105_student_term_activation.sql'));
$pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/20261106_student_term_grace.sql'));
$fixtureToday = new DateTimeImmutable('today', knsAcademicTermZone());
$fixtureYear = $fixtureToday->format('Y') . '-' . ((int) $fixtureToday->format('Y') + 1);
(new KnsAcademicTerms($pdo))->schedule(['academic_year'=>$fixtureYear,'semester'=>'1st Semester',
    'start_date'=>$fixtureToday->modify('-1 day')->format('Y-m-d'),
    'official_end_date'=>$fixtureToday->modify('+365 days')->format('Y-m-d')], 1);
$checks = 0;
function checkSetup(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejectSetup(callable $action, string $message): void
{
    try { $action(); } catch (InvalidArgumentException $error) { checkSetup(true, $message); return; }
    throw new RuntimeException($message);
}
function setupRow(PDO $pdo, string $number): array
{
    $stmt = $pdo->prepare('SELECT * FROM students WHERE student_number = ?');
    $stmt->execute([$number]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
function setupValues(string $number): array
{
    global $fixtureYear;
    return ['student_number' => $number, 'first_name' => 'Example', 'last_name' => 'Student',
        'course' => 'BSCS', 'year_level' => 1, 'section' => 'TEST', 'email' => 'student@example.invalid',
        'contact_number' => '09171234567', 'status' => 'Active', 'academic_year' => $fixtureYear, 'semester' => '1st Semester'];
}
$oldHash = password_hash('Fixture-existing-password', PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO students (student_number,first_name,last_name,course,year_level,section,password_hash,profile_photo)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute(['EXISTING', 'Existing', 'Student', 'BSCS', 1, 'TEST', $oldHash, 'uploads/students/fixture.png']);
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261105_student_account_setup.sql');
$pdo->exec($migration);
$pdo->exec($migration);
$row = setupRow($pdo, 'EXISTING');
checkSetup((int) $row['requires_password_change'] === 0 && $row['temporary_password_expires_at'] === null, 'Migration defaults changed existing login');
checkSetup($row['password_hash'] === $oldHash && $row['profile_photo'] === 'uploads/students/fixture.png', 'Migration changed password/photo');
$messages = [];
$fake = function (string $channel, string $to, string $subject, string $body) use (&$messages, $pdo): void {
    checkSetup(!$pdo->inTransaction(), 'Delivery held a transaction');
    preg_match('/temporary password: ([A-Za-z2-9]{8})(?=[.\s]|$)/i', $body, $match);
    checkSetup(isset($match[1]), 'Temporary password is not readable plain text');
    $messages[] = ['channel' => $channel, 'to' => $to, 'subject' => $subject, 'body' => $body, 'password' => $match[1]];
};
$service = new KnsStudentAccountSetup($pdo, $fake);
$input = setupValues('EMAIL');
$input['password'] = 'Legacy-workbook-value';
$input['password_hash'] = $oldHash;
$input['profile_photo'] = 'unexpected.png';
$result = $service->saveImportedStudent($input);
$row = setupRow($pdo, 'EMAIL');
$temporary = $messages[0]['password'];
checkSetup($result['created'] && $result['delivery']['channel'] === 'email', 'New account not emailed');
checkSetup($messages[0]['to'] === 'student@example.invalid', 'Wrong saved email');
checkSetup(str_contains($messages[0]['body'], 'Example Student') && str_contains($messages[0]['body'], 'EMAIL'), 'Email missing student identity');
foreach (['7 days', 'first login', 'can no longer be used', 'Do not share'] as $rule) checkSetup(str_contains($messages[0]['body'], $rule), 'Email missing rule');
checkSetup($messages[0]['subject'] === 'KNS Portal account setup', 'OTP subject reused');
checkSetup(password_verify($temporary, $row['password_hash']) && $row['password_hash'] !== $temporary, 'Temporary hash storage failed');
checkSetup(!password_verify('Legacy-workbook-value', $row['password_hash']) && $row['profile_photo'] === null, 'Legacy password/photo was imported');
checkSetup((int) $row['requires_password_change'] === 1, 'Account not pending');
$duration = strtotime($row['temporary_password_expires_at'] . ' UTC') - time();
checkSetup($duration > 604790 && $duration <= 604800, 'Expiry is not seven days');
checkSetup(!str_contains(json_encode($result), $temporary), 'Plaintext leaked in result');
$before = count($messages);
$service->saveImportedStudent(['student_number' => 'EXISTING', 'section' => 'UPDATED', 'password' => 'Workbook-overwrite', 'profile_photo' => 'bad.png']);
$row = setupRow($pdo, 'EXISTING');
checkSetup($row['section'] === 'UPDATED' && $row['password_hash'] === $oldHash && count($messages) === $before, 'Existing account password overwritten or resent');
checkSetup($row['profile_photo'] === 'uploads/students/fixture.png', 'Import changed existing photo');
$pending = setupRow($pdo, 'EMAIL');
$service->saveImportedStudent(['student_number' => 'EMAIL', 'section' => 'UPDATED']);
checkSetup(setupRow($pdo, 'EMAIL')['password_hash'] === $pending['password_hash'] && count($messages) === $before, 'Reimport implicitly resent pending password');
$input = setupValues('NO-CONTACT');
$input['email'] = null; $input['contact_number'] = 'invalid';
rejectSetup(fn() => $service->saveImportedStudent($input), 'Undeliverable account accepted');
checkSetup(setupRow($pdo, 'NO-CONTACT') === [], 'Undeliverable account saved');
$input = setupValues('PHONE'); $input['email'] = null;
$result = $service->saveImportedStudent($input);
$last = $messages[array_key_last($messages)];
checkSetup($result['delivery']['channel'] === 'sms' && $last['to'] === '+639171234567', 'Missing email did not use normalized SMS');
checkSetup(str_contains($last['body'], 'PHONE') && str_contains($last['body'], 'Expires') && str_contains($last['body'], 'first login'), 'SMS missing setup instructions');
$attempts = [];
$fallback = new KnsStudentAccountSetup($pdo, function ($channel, $to, $subject, $body) use (&$attempts, $fake): void {
    $attempts[] = $channel;
    if ($channel === 'email') throw new RuntimeException('Simulated SMTP failure');
    $fake($channel, $to, $subject, $body);
});
$result = $fallback->saveImportedStudent(setupValues('FALLBACK'));
checkSetup($attempts === ['email', 'sms'] && $result['delivery']['ok'], 'Email failure did not fall back to SMS');
$failing = new KnsStudentAccountSetup($pdo, function (): void { throw new RuntimeException('Simulated provider failure'); });
$result = $failing->saveImportedStudent(setupValues('FAILED'));
$row = setupRow($pdo, 'FAILED');
checkSetup(!$result['delivery']['ok'] && (int) $row['requires_password_change'] === 1, 'Delivery failure did not preserve pending account');
$old = $row['password_hash'];
checkSetup($service->resend((int) $row['id'])['ok'] && setupRow($pdo, 'FAILED')['password_hash'] !== $old, 'Failed account cannot receive fresh password');
$id = (int) $pending['id'];
$proof = hash('sha256', $pending['password_hash']);
$service->resend($id);
$fresh = $messages[array_key_last($messages)]['password'];
$row = setupRow($pdo, 'EMAIL');
checkSetup($fresh !== $temporary && !password_verify($temporary, $row['password_hash']) && password_verify($fresh, $row['password_hash']), 'Resend did not invalidate previous temporary password');
rejectSetup(fn() => $service->complete($id, $proof, 'Fixture-new-password', 'Fixture-new-password'), 'Stale setup session accepted');
$proof = hash('sha256', $row['password_hash']);
rejectSetup(fn() => $service->complete($id, $proof, 'short', 'short'), 'Weak setup password accepted');
rejectSetup(fn() => $service->complete($id, $proof, 'Fixture-new-password', 'Mismatch-password'), 'Password confirmation ignored');
rejectSetup(fn() => $service->complete($id, $proof, $fresh, $fresh), 'Temporary password reused as permanent password');
$pdo->exec('UPDATE students SET temporary_password_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ' . $id);
rejectSetup(fn() => $service->complete($id, $proof, 'Fixture-new-password', 'Fixture-new-password'), 'Expired setup password accepted');
$service->resend($id);
$row = setupRow($pdo, 'EMAIL');
$service->complete($id, hash('sha256', $row['password_hash']), 'Fixture-new-password', 'Fixture-new-password');
$row = setupRow($pdo, 'EMAIL');
checkSetup(!(int) $row['requires_password_change'] && $row['temporary_password_expires_at'] === null && password_verify('Fixture-new-password', $row['password_hash']), 'Setup did not clear pending fields');
rejectSetup(fn() => $service->resend($id), 'Completed account received temporary password');
$input = setupValues('INACTIVE'); $input['status'] = 'Inactive'; $input['email'] = null; $input['contact_number'] = null;
$result = $service->saveImportedStudent($input);
$row = setupRow($pdo, 'INACTIVE');
checkSetup($result['delivery'] === null && $row['password_hash'] === null && (int) $row['requires_password_change'] === 1, 'Inactive import issued a password');
rejectSetup(fn() => $service->resend((int) $row['id']), 'Inactive account received password');
$service->saveImportedStudent(array_replace(setupValues('INACTIVE'), ['email' => 'activated@example.invalid']));
checkSetup(setupRow($pdo, 'INACTIVE')['password_hash'] !== null, 'Activation did not provision account');
// Recovery must clear setup and expiry even when the temporary password has expired.
$pdo->exec("UPDATE students SET temporary_password_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE student_number = 'FAILED'");
$otp = '';
$recovery = new KnsPasswordResetService($pdo, function ($channel, $to, $code) use (&$otp): void { $otp = $code; });
$session = [];
$recovery->lookup('FAILED', $session, 'setup-test');
$recovery->request('FAILED', 'email', $session, 'setup-test');
$recovery->verify($otp, $session, 'setup-test');
$recovery->reset('Fixture-recovery-password', 'Fixture-recovery-password', $session, 'setup-test');
$row = setupRow($pdo, 'FAILED');
checkSetup(!(int) $row['requires_password_change'] && $row['temporary_password_expires_at'] === null, 'Recovery left temporary requirement');
$book = \PhpOffice\PhpSpreadsheet\IOFactory::load(__DIR__ . '/../downloads/KNS_Student_Upload_Template.xlsx');
$headers = $book->getSheetByName('Student Upload')->rangeToArray('A1:Q1')[0];
checkSetup(!in_array('password', $headers, true) && count($headers) === 17 && in_array('email', $headers, true) && in_array('contact_number', $headers, true), 'Workbook headers changed unexpectedly');
checkSetup($book->getSheetByName('Student Upload')->getCell('K2')->getDataType() === 's', 'Phone text formatting lost');
$book->disconnectWorksheets();
echo "PASS: $checks student setup checks; disposable database only; no real messages sent.\n";
} finally {
    $pdo = null;
    $source->exec('DROP DATABASE ' . $database);
}
