<?php
require __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/office-account-common.php';

function officeAccountsReply(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => $status < 400] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    officeAccountsReply(405, ['message' => 'Use GET to list accounts or POST to create or deactivate an account.']);
}
$adminId = $_SESSION['admin_id'] ?? null;
if (!is_int($adminId) || $adminId < 1 || isset($_SESSION['student_id']) || isset($_SESSION['office_id'])) {
    officeAccountsReply(isset($_SESSION['student_id']) || isset($_SESSION['office_id']) ? 403 : 401,
        ['message' => 'Only a signed-in Admin can manage office accounts.']);
}
if ($method === 'POST') {
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        officeAccountsReply(403, ['message' => 'Your form expired. Reload Admin Profile and try again.']);
    }
}
try {
    require __DIR__ . '/../../db.php';
    $service = new KnsOfficeAccountsService($pdo);
    if ($method === 'GET') officeAccountsReply(200, ['accounts' => $service->accounts($_SESSION)]);
    $action = $_POST['action'] ?? null;
    if ($action === 'create') {
        $account = $service->create($_SESSION, $_POST);
        officeAccountsReply(201, ['account' => $account, 'message' => $account['role'] . ' account created for ' . $account['full_name'] . '.']);
    }
    if ($action === 'deactivate') {
        $attempts = array_values(array_filter($_SESSION['office_password_attempts'] ?? [], fn($time) => is_int($time) && $time > time() - 300));
        if (count($attempts) >= 5) officeAccountsReply(429, ['message' => 'Too many incorrect Admin passwords. Try again in five minutes.']);
        try {
            $account = $service->deactivate($_SESSION, $_POST);
        } catch (KnsOfficeAccountError $error) {
            if (isset($error->errors['admin_password'])) {
                $attempts[] = time();
                $_SESSION['office_password_attempts'] = $attempts;
            }
            throw $error;
        }
        unset($_SESSION['office_password_attempts']);
        officeAccountsReply(200, ['account' => $account, 'message' => $account['full_name'] . ' has been deactivated. Request history is preserved.']);
    }
    officeAccountsReply(422, ['message' => 'Choose a valid account action.']);
} catch (KnsOfficeAccountError $error) {
    officeAccountsReply($error->status, ['message' => $error->getMessage(), 'errors' => $error->errors]);
} catch (Throwable $error) {
    // Never expose queries, credentials, password inputs, or hashes in an API response/log.
    error_log('Office account operation failed (' . get_class($error) . ', ' . $error->getCode() . ').');
    if ($error instanceof PDOException && in_array($error->getCode(), ['42S22', '42S02'], true)) {
        officeAccountsReply(503, ['message' => 'Office account storage is not ready. Apply the Office Accounts database migration.']);
    }
    officeAccountsReply(500, ['message' => 'Unable to complete the account request. Please try again.']);
}
