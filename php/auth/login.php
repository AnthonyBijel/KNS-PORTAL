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

$studentNumber = $_POST['student_number'] ?? '';
$loginPassword = $_POST['password'] ?? '';
if (!is_string($studentNumber) || !is_string($loginPassword)
    || !mb_check_encoding($studentNumber, 'UTF-8') || mb_strlen($studentNumber, 'UTF-8') > 30
    || trim($studentNumber) === '' || $loginPassword === '' || strlen($loginPassword) > 72) {
    $_SESSION['login_error'] = 'Enter a valid student number and password.';
    header('Location: ../../login.php');
    exit;
}
$studentNumber = trim($studentNumber);

require __DIR__ . '/../../db.php';
require_once __DIR__ . '/../student-term-enrollments.php';
knsSyncStudentTermByNumber($pdo, $studentNumber);

$stmt = $pdo->prepare(
    'SELECT id, password_hash, status, requires_password_change, temporary_password_expires_at > UTC_TIMESTAMP() AS temporary_password_live
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
    if ((int) $student['requires_password_change'] && !(int) $student['temporary_password_live']) {
        $_SESSION['login_error'] = 'Your temporary password has expired. Use Forgot Password below to create a new password.';
        header('Location: ../../login.php');
        exit;
    }
    session_regenerate_id(true);
    // Replace all previous account data, including the other account role.
    $_SESSION = [];

    $_SESSION['student_id'] = (int) $student['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    if ((int) $student['requires_password_change']) {
        $_SESSION['student_setup_hash'] = hash('sha256', $student['password_hash']);
        header('Location: ../../change-password.php');
    } else {
        header('Location: ../../dashboard/dashboard.php');
    }
    exit;
}

$_SESSION['login_error'] =
    'Invalid student number or password, or account is inactive.';

header('Location: ../../login.php');
exit;
