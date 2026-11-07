<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/email-change.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (currentStudentId() === null) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'message'=>'Sign in to your student account to use Settings.']);
    exit;
}
require __DIR__ . '/../../db.php';
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) {
        header('Allow: GET, POST');
        throw new KnsStudentSettingsError('Use GET to load settings or POST to save.', 405);
    }
    $protected = ['id','student_id','student_number','admin_id','office_id','account_id','user_id','role',
        'first_name','middle_name','last_name','suffix','full_name','school_name','course','year_level',
        'section','status','academic_year','semester','requires_password_change','password_hash','email'];
    foreach ($protected as $key) {
        if (array_key_exists($key, $_GET) || array_key_exists($key, $_POST)) {
            throw new KnsStudentSettingsError('Settings belong only to your signed-in account. Identity and academic records are read-only.', 403);
        }
    }
    $settings = new KnsStudentSettings($pdo);
    if ($method === 'GET') {
        $result = ['state'=>$settings->state($_SESSION)];
    } else {
        $csrf = $_POST['csrf'] ?? null;
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) throw new KnsStudentSettingsError('The form expired. Reopen Settings and try again.', 403);
        $action = $_POST['action'] ?? null;
        if (!is_string($action)) throw new KnsStudentSettingsError('Choose a settings action.');
        $allowed = match ($action) {
            'notifications'=>['notify_announcements','notify_requests','notify_activity','notify_email'],
            'security'=>['current_password','new_password','password_confirmation'],
            'contacts'=>['phone_number','alternate_contact','address'],
            'appearance'=>['theme'], 'language'=>['language'],
            'send_email'=>['new_email','email_confirmation'], 'verify_email'=>['verification_code'],
            default=>[]
        };
        foreach (array_keys($_POST) as $key) {
            if (!in_array($key, ['csrf','action','kns_tab',...$allowed], true)) throw new KnsStudentSettingsError('This field cannot be changed in these settings.', 403);
        }
        $result = match ($action) {
            'send_email'=>(new KnsStudentEmailChange($pdo))->send($_SESSION, $_POST),
            'verify_email'=>(new KnsStudentEmailChange($pdo))->verify($_SESSION, $_POST),
            default=>$settings->save($_SESSION, $action, $_POST)
        };
        if (in_array($action, ['security','contacts','verify_email'], true)) {
            unset($_SESSION['password_reset_lookup'],$_SESSION['password_reset_otp'],$_SESSION['password_reset_grant']);
        }
        if ($action === 'security') session_regenerate_id(true);
    }
    echo json_encode(['ok'=>true,'csrf'=>$_SESSION['csrf']] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (KnsStudentSettingsError $error) {
    http_response_code($error->status);
    echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Student settings failed (' . get_class($error) . ').');
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Unable to save your settings. Please try again.']);
}
