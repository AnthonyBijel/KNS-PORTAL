<?php
function knsPortalUrl(string $path): string
{
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $relative = substr($script, strlen($root));
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = $relative !== '' && str_ends_with($name, $relative) ? substr($name, 0, -strlen($relative)) : '';
    return $base . '/' . ltrim($path, '/');
}

function knsSetupBlocked(string $url, string $message, int $status = 403): never
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $api = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || (str_contains($script, '/php/') && !str_contains($script, '/php/auth/'));
    if ($api) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message, 'redirect' => $url], JSON_UNESCAPED_SLASHES);
    } else {
        header('Location: ' . $url, true, 303);
    }
    exit;
}

function knsEnforceStudentSetup(): void
{
    $id = currentStudentId();
    if ($id === null) return;
    // Authentication/recovery/logout remain reachable while setup is pending.
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    foreach (['login.php', 'php/auth/login.php', 'php/auth/admin_login.php', 'php/auth/logout.php',
        'php/auth/request_password_reset.php', 'php/auth/verify_password_reset.php', 'php/auth/reset_password.php'] as $public) {
        if ($script === realpath(dirname(__DIR__, 2) . '/' . $public)) return;
    }
    try {
        // Also covers old sessions and sessions superseded by an Admin resend.
        require __DIR__ . '/../../db.php';
        require_once __DIR__ . '/../student-term-enrollments.php';
        knsSyncStudentTerm($pdo, $id);
        $statement = $pdo->prepare('SELECT status, password_hash, requires_password_change,
            temporary_password_expires_at > UTC_TIMESTAMP() AS temporary_password_live FROM students WHERE id = ?');
        $statement->execute([$id]);
        $student = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $error) {
        http_response_code(503);
        exit('Student account setup is unavailable. Ask the administrator to apply 20261105_student_account_setup.sql.');
    }
    $pending = $student && (int) $student['requires_password_change'];
    $proof = $_SESSION['student_setup_hash'] ?? null;
    if (!$student || $student['status'] !== 'Active'
        || ($pending && (!(int) $student['temporary_password_live'] || !is_string($proof)))
        || ($proof !== null && (!is_string($proof) || !$pending
            || !hash_equals(hash('sha256', (string) $student['password_hash']), $proof)))) {
        unset($_SESSION['student_id'], $_SESSION['student_setup_hash']);
        $_SESSION['login_error'] = 'Your setup session expired or was replaced. Sign in again or use Forgot Password.';
        knsSetupBlocked(knsPortalUrl('login.php'), $_SESSION['login_error'], 401);
    }
    if ($pending && $script !== realpath(dirname(__DIR__, 2) . '/change-password.php')) {
        knsSetupBlocked(knsPortalUrl('change-password.php'), 'Create a new password before accessing the portal.');
    }
}
