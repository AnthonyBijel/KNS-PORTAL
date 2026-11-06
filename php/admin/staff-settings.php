<?php
require_once __DIR__ . '/../auth/staff-access.php';
require_once __DIR__ . '/staff-email-change.php';
require __DIR__ . '/../../db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
knsRequireStaffAccess($pdo, 'profile', '../../login.php');
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) {
        header('Allow: GET, POST'); throw new KnsStaffSettingsError('Use GET to load settings or POST to save.', 405);
    }
    foreach (['id','admin_id','account_id','user_id','role','username','is_active'] as $key) {
        if (array_key_exists($key, $_GET) || array_key_exists($key, $_POST)) throw new KnsStaffSettingsError('Settings belong only to your signed-in account. Account identity and role are read-only.', 403);
    }
    $settings = new KnsStaffSettings($pdo);
    if ($method === 'GET') {
        $result = ['state'=>$settings->state($_SESSION)];
    } else {
        $csrf = $_POST['csrf'] ?? null;
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) throw new KnsStaffSettingsError('The form expired. Reopen Settings and try again.', 403);
        $action = $_POST['action'] ?? null;
        if (!is_string($action)) throw new KnsStaffSettingsError('Choose a settings action.');
        if ($action === 'send_email') $result = (new KnsStaffEmailChange($pdo))->send($_SESSION, $_POST);
        elseif ($action === 'verify_email') $result = (new KnsStaffEmailChange($pdo))->verify($_SESSION, $_POST);
        else $result = $settings->save($_SESSION, $action, $_POST);
        if (isset($result['state']['profile']['full_name'])) {
            $_SESSION[currentOfficeId() !== null ? 'office_name' : 'admin_name'] = $result['state']['profile']['full_name'];
        }
        if ($action === 'security') session_regenerate_id(true);
    }
    echo json_encode(['ok'=>true, 'csrf'=>$_SESSION['csrf']] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (KnsStaffSettingsError $error) {
    http_response_code($error->status); echo json_encode(['ok'=>false, 'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Staff settings failed (' . get_class($error) . ').');
    http_response_code(500); echo json_encode(['ok'=>false,'message'=>'Unable to save your settings. Please try again.']);
}
