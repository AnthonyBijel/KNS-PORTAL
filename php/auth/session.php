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

header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function requireStudent()
{
    if (empty($_SESSION['student_id'])) {
        header('Location: ../login.php');
        exit;
    }
}
