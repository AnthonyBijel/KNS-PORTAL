<?php

ini_set('session.use_strict_mode', '1');

session_name('kns_student_portal');

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);

session_start();

// A browser session represents one valid account and one role at a time.
$hasStudentIdentity = isset($_SESSION['student_id']);
$hasAdminIdentity = isset($_SESSION['admin_id']);
if (($hasStudentIdentity && (!is_int($_SESSION['student_id']) || $_SESSION['student_id'] < 1))
    || ($hasAdminIdentity && (!is_int($_SESSION['admin_id']) || $_SESSION['admin_id'] < 1))
    || ($hasStudentIdentity && $hasAdminIdentity)) {
    $_SESSION = [];
    session_regenerate_id(true);
}

header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function currentStudentId(): ?int
{
    $studentId = $_SESSION['student_id'] ?? null;
    return is_int($studentId) && $studentId > 0 && !isset($_SESSION['admin_id'])
        ? $studentId : null;
}

function requireStudent(): void
{
    if (currentStudentId() === null) {
        header('Location: ../login.php');
        exit;
    }
}
