<?php
// Local development smoke test only. This does not enable the portal login yet.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Open this test on the server computer through localhost.');
}

ini_set('session.use_strict_mode', '1');
session_name('kns_local_auth_test');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/db.php';

function escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$schemaReady = false;
$student = null;
$schemaError = '';
try {
    $pdo->query('SELECT id, student_number, password_hash, first_name, last_name, course, year_level, section, status FROM students LIMIT 0');
    $schemaReady = true;
} catch (PDOException $error) {
    $schemaError = 'Connected to the database, but the expected students columns are missing. Check the students table and password_hash column.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('Session expired. Reload the page and try again.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'logout') {
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $cookie['path'],
            'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_destroy();
        header('Location: test_student_login.php', true, 303);
        exit;
    }
    $password = $_POST['password'] ?? '';
    if (!$schemaReady) {
        $message = $schemaError;
    } elseif (!is_string($password) || strlen($password) > 72) {
        $message = 'Use a password of at most 72 bytes.';
    } else {
        try {
            if ($action === 'create') {
                if (strlen($password) < 12) {
                    $message = 'Choose a test password with at least 12 characters.';
                } else {
                    // Fixed test ID: never overwrite an existing student or password.
                    $stmt = $pdo->prepare('INSERT INTO students (student_number, password_hash, first_name, last_name, course, year_level, section, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute(['TEST-LOGIN-001', password_hash($password, PASSWORD_DEFAULT), 'Test', 'Student', 'BS Computer Science', 1, 'TEST', 'Active']);
                    $message = 'Test student saved. Log in using TEST-LOGIN-001 and the password you just entered.';
                }
            } elseif ($action === 'login') {
                $number = $_POST['student_number'] ?? '';
                if (!is_string($number)) $number = '';
                $attempts = $_SESSION['attempts'] ?? ['count' => 0, 'since' => time()];
                if (time() - $attempts['since'] >= 60) $attempts = ['count' => 0, 'since' => time()];
                if ($attempts['count'] >= 5) {
                    $message = 'Too many attempts in this test session. Wait one minute.';
                } else {
                    $attempts['count']++;
                    $_SESSION['attempts'] = $attempts;
                    $stmt = $pdo->prepare('SELECT id, password_hash, status FROM students WHERE student_number = ? LIMIT 1');
                    $stmt->execute([trim($number)]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($account && $account['status'] === 'Active' && !empty($account['password_hash']) && password_verify($password, $account['password_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['student_id'] = (int) $account['id'];
                        $_SESSION['csrf'] = bin2hex(random_bytes(32));
                        unset($_SESSION['attempts']);
                        $message = 'Login successful. The information below was read from your database.';
                    } else {
                        $message = 'Invalid student number/password, or account is inactive. You are not logged in.';
                    }
                }
            }
        } catch (PDOException $error) {
            $message = $error->getCode() === '23000'
                ? 'The test account already exists, or a database constraint blocked this insert. Existing data was not overwritten.'
                : 'Database operation failed. Check the students table definition and local PHP error log.';
            error_log('KNS local auth test: ' . $error->getMessage());
        }
    }
    $_SESSION['flash'] = $message;
    header('Location: test_student_login.php', true, 303);
    exit;
}

if ($schemaReady && isset($_SESSION['student_id'])) {
    $stmt = $pdo->prepare('SELECT student_number, first_name, last_name, course, year_level, section, status FROM students WHERE id = ?');
    $stmt->execute([$_SESSION['student_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student || $student['status'] !== 'Active') {
        unset($_SESSION['student_id']);
        $student = null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KNS student login test</title>
    <style>
        *{box-sizing:border-box}body{font:16px/1.5 system-ui,sans-serif;background:#eef4f1;color:#15382b;margin:0;padding:32px 16px}main{max-width:640px;margin:auto}section{background:white;padding:24px;border-radius:14px;margin:18px 0;border:1px solid #ceded5}h1{line-height:1.2}h2{font-size:20px}label{display:block;margin:14px 0}input{display:block;width:100%;padding:12px;border:1px solid #819b8e;border-radius:6px;font:inherit;margin-top:5px}button{background:#17623f;color:white;border:0;border-radius:6px;padding:12px 20px;font:inherit;cursor:pointer}button:disabled{opacity:.5}aside{background:#e0edff;color:#18365f;padding:16px;border-radius:8px}dt{font-weight:bold}dd{margin:0 0 14px;overflow-wrap:anywhere}.muted{color:#53665b}
    </style>
</head>
<body><main>
    <h1>Student login test</h1>
    <p class="muted">Local database + session test. This is separate from the portal login.</p>
    <section>
        <strong>Database connection: successful</strong>
        <p>Students table: <?= $schemaReady ? 'ready' : escape($schemaError) ?></p>
        <p>Session: <strong><?= $student ? 'Logged in' : 'Logged out' ?></strong></p>
    </section>
    <?php if ($message !== ''): ?><aside role="status"><?= escape($message) ?></aside><?php endif; ?>
    <?php if ($student): ?>
        <section><h2>Your student record</h2><dl>
            <?php foreach ($student as $field => $value): ?>
                <dt><?= escape(ucwords(str_replace('_', ' ', $field))) ?></dt><dd><?= escape($value) ?></dd>
            <?php endforeach; ?>
        </dl>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit">Log out</button>
        </form></section>
    <?php else: ?>
        <section><h2>1. Create a test student (once)</h2>
            <p>Student number: <strong>TEST-LOGIN-001</strong>. Choose a password below. Only its hash is saved.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="create">
                <label>Test password<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
                <button type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Create test student</button>
            </form>
        </section>
        <section><h2>2. Log in</h2><form method="post">
            <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="login">
            <label>Student number<input name="student_number" value="TEST-LOGIN-001" maxlength="30" autocomplete="username" required></label>
            <label>Password<input type="password" name="password" maxlength="72" autocomplete="current-password" required></label>
            <button type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Log in</button>
        </form></section>
    <?php endif; ?>
</main></body></html>
