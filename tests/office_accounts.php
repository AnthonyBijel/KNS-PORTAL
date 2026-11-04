<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../php/admin/office-account-common.php';
require __DIR__ . '/../php/auth/staff-accounts.php';
require __DIR__ . '/../db.php';
$checks = 0;
function officeCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function officeRejects(callable $action, int $status, string $label): void {
    try { $action(); } catch (KnsOfficeAccountError $error) { officeCheck($error->status === $status, $label); return; }
    throw new RuntimeException($label);
}
// Temporary tables shadow real accounts on this connection only.
$definition = $pdo->query('SHOW CREATE TABLE admin_account')->fetch(PDO::FETCH_NUM)[1];
$pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition, 1));
$pdo->exec('CREATE TEMPORARY TABLE request_messages (id INT PRIMARY KEY, sender_admin_id TINYINT UNSIGNED, body TEXT)');
$adminPassword = 'Temporary-Admin-Password-Only';
$statement = $pdo->prepare('INSERT INTO admin_account (id, username, password_hash, admin_name, role, school_name, school_email, school_contact)
    VALUES (1, ?, ?, ?, ?, ?, ?, ?)');
$statement->execute(['test.admin', password_hash($adminPassword, PASSWORD_DEFAULT), 'Test Admin', 'Administrator', 'Test School', 'admin@example.test', '09123456789']);
$service = new KnsOfficeAccountsService($pdo);
$admin = ['admin_id' => 1];
$valid = ['full_name' => 'Registrar Staff', 'username' => 'registrar.test', 'email' => 'registrar@example.test',
    'phone_number' => '+63 912 345 6789', 'role' => 'Registrar', 'password' => 'Temporary-Office-Password-Only',
    'password_confirmation' => 'Temporary-Office-Password-Only'];
officeCheck($service->accounts($admin) === [], 'Admin is not listed as an office account');
officeRejects(fn() => $service->accounts([]), 401, 'Anonymous list rejected');
foreach ([['student_id' => 1], ['office_id' => 2], ['admin_id' => 1, 'student_id' => 1], ['admin_id' => 1, 'office_id' => 2]] as $session) {
    officeRejects(fn() => $service->accounts($session), 403, 'Non-Admin list rejected');
    officeRejects(fn() => $service->create($session, $valid), 403, 'Non-Admin creation rejected');
    officeRejects(fn() => $service->deactivate($session, ['account_id' => 2, 'admin_password' => $adminPassword]), 403, 'Non-Admin deactivation rejected');
}
foreach (['full_name', 'username', 'email', 'phone_number', 'role', 'password', 'password_confirmation'] as $key) {
    $invalid = $valid; unset($invalid[$key]);
    officeRejects(fn() => $service->create($admin, $invalid), 422, 'Missing required field ' . $key);
}
foreach (['Administrator', 'Admin', 'student', 'Teacher', 'registrar'] as $role) {
    officeRejects(fn() => $service->create($admin, array_replace($valid, ['role' => $role])), 422, 'Privilege-escalating or invalid role rejected');
}
foreach ([
    ['email' => 'bad-email'], ['phone_number' => 'not-a-phone'], ['username' => "bad' OR 1=1"],
    ['password' => 'short', 'password_confirmation' => 'short'],
    ['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)],
    ['password_confirmation' => 'different'], ['role' => ['Registrar']], ['full_name' => []],
] as $changes) {
    officeRejects(fn() => $service->create($admin, array_replace($valid, $changes)), 422, 'Malformed registration rejected');
}
$office = $service->create($admin, $valid);
officeCheck($office['role'] === 'Registrar' && $office['phone_number'] === $valid['phone_number'], 'Role and phone saved');
officeCheck($office['is_active'] && !isset($office['password_hash']) && !isset($office['password']), 'Safe active public account');
$hash = $pdo->query('SELECT password_hash FROM admin_account WHERE id = ' . $office['id'])->fetchColumn();
officeCheck(password_verify($valid['password'], $hash) && $hash !== $valid['password'], 'Password stored only as a hash');
officeCheck(knsStaffAuthenticate($pdo, $valid['username'], $valid['password'])['role'] === 'Registrar', 'Office login uses assigned role');
officeCheck(knsStaffAuthenticate($pdo, 'test.admin', $adminPassword)['role'] === 'Administrator', 'Existing Admin login supported');
officeCheck(knsStaffAuthenticate($pdo, $valid['username'], 'wrong') === null, 'Wrong login password rejected');
officeRejects(fn() => $service->create($admin, array_replace($valid, ['username' => 'REGISTRAR.TEST', 'email' => 'different@example.test'])), 409, 'Case-insensitive duplicate username');
officeRejects(fn() => $service->create($admin, array_replace($valid, ['username' => 'another.user', 'email' => 'REGISTRAR@EXAMPLE.TEST'])), 409, 'Case-insensitive duplicate email');
officeRejects(fn() => $service->create(['admin_id' => $office['id']], $valid), 403, 'Office ID cannot impersonate Admin');
$osa = $service->create($admin, array_replace($valid, ['full_name' => 'OSA Staff', 'username' => 'osa.test', 'email' => 'osa@example.test', 'role' => 'OSA']));
officeCheck(count($service->accounts($admin)) === 2 && $osa['role'] === 'OSA', 'Both office roles are listed');
$json = json_encode($service->accounts($admin));
officeCheck(!str_contains($json, 'password') && !str_contains($json, $hash), 'List never exposes password material');
$statement = $pdo->prepare('INSERT INTO request_messages VALUES (1, ?, ?)');
$statement->execute([$office['id'], 'Historical office reply']);
officeRejects(fn() => $service->deactivate($admin, ['account_id' => $office['id'], 'admin_password' => $valid['password']]), 422, 'Office password cannot authorize deactivation');
officeRejects(fn() => $service->deactivate($admin, ['account_id' => 1, 'admin_password' => $adminPassword]), 404, 'Admin cannot be deactivated by this feature');
officeRejects(fn() => $service->deactivate($admin, ['account_id' => $office['id'], 'admin_password' => 'wrong']), 422, 'Wrong Admin password rejected');
$changedPassword = 'Changed-Temporary-Admin-Password';
$statement = $pdo->prepare('UPDATE admin_account SET password_hash = ? WHERE id = 1');
$statement->execute([password_hash($changedPassword, PASSWORD_DEFAULT)]);
officeRejects(fn() => $service->deactivate($admin, ['account_id' => $office['id'], 'admin_password' => $adminPassword]), 422, 'Old Admin password cannot authorize deactivation');
$deactivated = $service->deactivate($admin, ['account_id' => $office['id'], 'admin_password' => $changedPassword]);
officeCheck(!$deactivated['is_active'], 'Current Admin password deactivates office account');
officeCheck(knsStaffAuthenticate($pdo, $valid['username'], $valid['password']) === null, 'Inactive office cannot sign in');
officeCheck((int) $pdo->query('SELECT COUNT(*) FROM request_messages')->fetchColumn() === 1, 'Request history preserved');
officeCheck(count($service->accounts($admin)) === 2, 'Inactive account stays in the list');
officeCheck((int) $pdo->query('SELECT COUNT(*) FROM admin_account')->fetchColumn() === 3, 'Deactivation never deletes accounts');
officeRejects(fn() => $service->create($admin, $valid), 409, 'Inactive usernames and emails remain reserved');
$pdo->exec('UPDATE admin_account SET is_active = 0 WHERE id = 1');
officeRejects(fn() => $service->accounts($admin), 403, 'Inactive Admin session rejected');
echo 'Passed ' . $checks . " office-account checks. Only temporary tables were used.\n";
