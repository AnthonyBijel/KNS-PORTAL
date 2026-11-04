<?php
require_once __DIR__ . '/session.php';

// Check the current database identity; office sessions never acquire admin_id.
function knsCurrentStaffAccount(PDO $pdo, bool $lock = false): ?array
{
    $officeId = currentOfficeId();
    $id = $officeId ?? ($_SESSION['admin_id'] ?? null);
    if (!is_int($id) || $id < 1 || currentStudentId() !== null) return null;
    $statement = $pdo->prepare('SELECT id, username, admin_name, role, school_name, school_email,
        school_contact, school_address, profile_photo, is_active FROM admin_account WHERE id = ?'
        . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$account || (int) $account['is_active'] !== 1
        || ($officeId !== null
            ? !in_array($account['role'], ['Registrar', 'OSA'], true)
            : $account['role'] !== 'Administrator')) return null;
    $account['id'] = (int) $account['id'];
    return $account;
}

function knsStaffMay(array $account, string $feature): bool
{
    $permissions = [
        'overview' => ['Administrator', 'Registrar', 'OSA'],
        'announcements' => ['Administrator', 'Registrar', 'OSA'],
        'results' => ['Administrator', 'Registrar'],
        'profile' => ['Administrator', 'Registrar', 'OSA'],
        'office_accounts' => ['Administrator'],
    ];
    return in_array($account['role'] ?? '', $permissions[$feature] ?? [], true);
}

function knsRequireStaffAccess(PDO $pdo, string $feature, string $login = '../login.php', bool $lock = false): array
{
    $account = knsCurrentStaffAccount($pdo, $lock);
    $status = $account === null ? 401 : (knsStaffMay($account, $feature) ? 200 : 403);
    if ($status === 200) return $account;
    $message = $status === 401 ? 'Your account is unavailable. Please sign in again.'
        : 'Your office role cannot access this feature.';
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok' => false, 'message' => $message]);
    } elseif ($status === 401) {
        header('Location: ' . $login, true, 302);
    } else {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
}
