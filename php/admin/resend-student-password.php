<?php
require_once __DIR__ . '/../auth/staff-access.php';
require __DIR__ . '/../../db.php';
$account = knsRequireStaffAccess($pdo, 'results', '../../login.php');
if ($account['role'] !== 'Administrator') {
    http_response_code(403);
    exit('Only the Administrator can issue temporary passwords.');
}
require __DIR__ . '/../auth/student-account-setup.php';
$status = 200;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        $status = 405;
        throw new InvalidArgumentException('Use the Students List action to send a fresh temporary password.');
    }
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        $status = 403;
        throw new InvalidArgumentException('The form expired. Reload the Students List and try again.');
    }
    $id = $_POST['student_id'] ?? '';
    if (!is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new InvalidArgumentException('Choose a valid student account.');
    }
    $delivery = (new KnsStudentAccountSetup($pdo))->resend((int) $id);
    if (!$delivery['ok']) {
        $status = 502;
        $message = 'Delivery failed. The account is still pending. Check its saved contacts and the existing recovery sender configuration, then use Send fresh temporary password again.';
    } else {
        $message = 'A fresh temporary password was sent by ' . ($delivery['channel'] === 'email' ? 'email' : 'SMS')
            . '. Earlier temporary passwords no longer work.';
    }
} catch (InvalidArgumentException $error) {
    if ($status === 200) $status = 422;
    $message = $error->getMessage();
} catch (Throwable $error) {
    $status = 500;
    $message = 'Unable to issue a temporary password. Check the account setup migration and retry.';
    error_log('Student temporary-password resend failed (' . get_class($error) . ').');
}
http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="en"><meta charset="utf-8"><title>Temporary password delivery</title>
<h1>Temporary password delivery</h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<p><a href="../../admin/dashboard.php?view=results">Back to Students List</a></p></html>
