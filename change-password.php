<?php
require __DIR__ . '/php/auth/session.php';
if (currentStudentId() === null) {
    header('Location: login.php', true, 303);
    exit;
}
if (!isset($_SESSION['student_setup_hash'])) {
    header('Location: dashboard/dashboard.php', true, 303);
    exit;
}
require __DIR__ . '/db.php';
require __DIR__ . '/php/auth/student-account-setup.php';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $token = $_POST['csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            http_response_code(403);
            throw new InvalidArgumentException('The form expired. Reload this page and try again.');
        }
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirmation'] ?? '';
        if (!is_string($password) || !is_string($confirmation)) {
            throw new InvalidArgumentException('Enter and confirm your new password.');
        }
        (new KnsStudentAccountSetup($pdo))->complete(currentStudentId(), $_SESSION['student_setup_hash'], $password, $confirmation);
        unset($_SESSION['student_setup_hash'], $_SESSION['password_reset_lookup'], $_SESSION['password_reset_otp'], $_SESSION['password_reset_grant']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        header('Location: dashboard/dashboard.php', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        if (http_response_code() !== 403) http_response_code(422);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        http_response_code(500);
        $error = 'Unable to change your password. Please try again.';
        error_log('Student password setup failed (' . get_class($exception) . ').');
    } finally {
        unset($password, $confirmation);
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <script src="js/tab-session.js?v=<?= filemtime(__DIR__ . '/js/tab-session.js') ?>"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create your password - KNS Portal</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eef4f1;color:#15382b;font:16px/1.5 system-ui,sans-serif}
        main{max-width:460px;margin:8vh auto;padding:32px;background:white;border-radius:12px;box-shadow:0 8px 30px #15382b18}
        label,input,button{display:block;width:100%}input{padding:12px;margin:6px 0 18px;border:1px solid #9aad9f;border-radius:6px;font:inherit}
        button{padding:12px;background:#17633e;color:white;border:0;border-radius:6px;font:inherit;cursor:pointer}
        .error{color:#a11}a{color:#17633e}.logout{margin-top:16px;background:white;color:#17633e;border:1px solid #17633e}
        @media(max-width:500px){main{margin:24px 16px;padding:24px}}
    </style>
</head>
<body><main>
    <p>KNS Portal</p><h1>Create your password</h1>
    <p>Choose a new password before continuing to the portal. Your temporary password will stop working once replaced. Do not share your password.</p>
    <p>Use at least 12 characters and at most 72 bytes.</p>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post" action="change-password.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="new-password">New password</label>
        <input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" required>
        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required>
        <button type="submit">Save password and continue</button>
    </form>
    <form method="post" action="php/auth/logout.php"><button class="logout" type="submit">Sign out</button></form>
</main></body></html>
