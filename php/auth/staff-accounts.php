<?php
// Staff identities share admin_account; office identities never receive admin_id.
function knsStaffRoles(): array
{
    return ['Administrator', 'Registrar', 'OSA'];
}

function knsStaffAuthenticate(PDO $pdo, string $username, string $password): ?array
{
    $statement = $pdo->prepare('SELECT id, username, password_hash, admin_name, role, is_active
        FROM admin_account WHERE username = ? LIMIT 1');
    $statement->execute([$username]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$account || (int) $account['is_active'] !== 1
        || !in_array($account['role'], knsStaffRoles(), true)
        || !password_verify($password, $account['password_hash'])) return null;
    // Password material stays inside this function.
    return ['id' => (int) $account['id'], 'username' => $account['username'],
        'full_name' => $account['admin_name'], 'role' => $account['role']];
}
