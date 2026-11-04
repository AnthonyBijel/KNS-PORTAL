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

require_once __DIR__ . '/staff-accounts.php';
$account = knsStaffAuthenticate($pdo, $adminUsername, $adminPassword);

if ($account !== null) {
    session_regenerate_id(true);
    $_SESSION = [];
    if ($account['role'] === 'Administrator') {
        $_SESSION['admin_id'] = $account['id'];
        $_SESSION['admin_name'] = $account['full_name'];
        $_SESSION['admin_role'] = $account['role'];
    } else {
        $_SESSION['office_id'] = $account['id'];
        $_SESSION['office_name'] = $account['full_name'];
        $_SESSION['office_role'] = $account['role'];
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    header('Location: ../../admin/dashboard.php');
    exit;
}

$_SESSION['login_error'] = 'Invalid staff username or password, or account is inactive.';
header('Location: ../../login.php');
exit;
