<?php

require __DIR__ . '/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../login.php');
    exit;
}

$token = $_POST['csrf'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf']) ||
    !hash_equals($_SESSION['csrf'], $token)
) {
    $_SESSION['login_error'] = 'Invalid or expired login form.';
    header('Location: ../../login.php');
    exit;
}

$adminUsername = $_POST['student_number'] ?? '';
$adminPassword = $_POST['password'] ?? '';
if (!is_string($adminUsername) || !is_string($adminPassword)
    || !mb_check_encoding($adminUsername, 'UTF-8') || mb_strlen($adminUsername, 'UTF-8') > 50
    || trim($adminUsername) === '' || $adminPassword === '') {
    $_SESSION['login_error'] = 'Enter a valid admin username and password.';
    header('Location: ../../login.php');
    exit;
}
$adminUsername = trim($adminUsername);

require __DIR__ . '/../../db.php';

$stmt = $pdo->prepare(
    'SELECT id, username, password_hash, admin_name, role
     FROM admin_account
     WHERE id = 1 AND username = ?
     LIMIT 1'
);

$stmt->execute([$adminUsername]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    $admin &&
    password_verify($adminPassword, $admin['password_hash'])
) {
    session_regenerate_id(true);
    // Replace all previous account data, including the other account role.
    $_SESSION = [];

    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = $admin['admin_name'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    header('Location: ../../admin/dashboard.php');
    exit;
}

$_SESSION['login_error'] =
    'Invalid admin username or password.';

header('Location: ../../login.php');
exit;
