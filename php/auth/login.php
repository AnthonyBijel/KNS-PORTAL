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

$studentNumber = trim($_POST['student_number'] ?? '');
$loginPassword = $_POST['password'] ?? '';

if ($studentNumber === '' || $loginPassword === '') {
    $_SESSION['login_error'] = 'Enter your student number and password.';
    header('Location: ../../login.php');
    exit;
}

require __DIR__ . '/../../db.php';

$stmt = $pdo->prepare(
    'SELECT id, password_hash, status
     FROM students
     WHERE student_number = ?
     LIMIT 1'
);

$stmt->execute([$studentNumber]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    $student &&
    $student['status'] === 'Active' &&
    !empty($student['password_hash']) &&
    password_verify($loginPassword, $student['password_hash'])
) {
    session_regenerate_id(true);

    $_SESSION['student_id'] = (int) $student['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    header('Location: ../../dashboard/dashboard.php');
    exit;
}

$_SESSION['login_error'] =
    'Invalid student number or password, or account is inactive.';

header('Location: ../../login.php');
exit;
