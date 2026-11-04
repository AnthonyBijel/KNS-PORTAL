<?php
require __DIR__ . '/session.php';

unset(
    $_SESSION['admin_id'],
    $_SESSION['admin_name'],
    $_SESSION['admin_role'],
    $_SESSION['office_id'],
    $_SESSION['office_name'],
    $_SESSION['office_role']
);

header('Location: ../../login.php');
exit;
