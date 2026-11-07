<?php

ini_set('session.use_strict_mode', '1');

$knsTab = $_GET['kns_tab'] ?? $_POST['kns_tab'] ?? '';
if (!is_string($knsTab) || ($knsTab !== '' && !preg_match('/\A[a-f0-9]{32}\z/', $knsTab))) {
    http_response_code(400);
    exit('Invalid tab session.');
}

// Each tab selects its own HttpOnly session cookie; the tab key is not a session ID.
session_name('kns_student_portal' . ($knsTab === '' ? '' : '_' . $knsTab));

if ($knsTab !== '') {
    header_register_callback(static function () use (&$knsTab): void {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') !== 0) continue;
            $location = trim(substr($header, 9));
            $parts = parse_url($location);
            if ($parts === false || isset($parts['scheme']) || isset($parts['host'])
                || preg_match('/(?:\?|&)kns_tab=/', $location)) continue;
            $fragment = strpos($location, '#');
            $suffix = $fragment === false ? '' : substr($location, $fragment);
            $location = $fragment === false ? $location : substr($location, 0, $fragment);
            $location .= (str_contains($location, '?') ? '&' : '?') . 'kns_tab=' . $knsTab;
            header('Location: ' . $location . $suffix, true, http_response_code());
        }
    });
}

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);

session_start();

// Each tab session has exactly one student, Admin, or office identity.
$hasStudentIdentity = isset($_SESSION['student_id']);
$hasAdminIdentity = isset($_SESSION['admin_id']);
$hasOfficeIdentity = isset($_SESSION['office_id']);
if (($hasStudentIdentity && (!is_int($_SESSION['student_id']) || $_SESSION['student_id'] < 1))
    || ($hasAdminIdentity && (!is_int($_SESSION['admin_id']) || $_SESSION['admin_id'] < 1))
    || ($hasOfficeIdentity && (!is_int($_SESSION['office_id']) || $_SESSION['office_id'] < 1
        || !in_array($_SESSION['office_role'] ?? null, ['Registrar', 'OSA'], true)))
    || (int) $hasStudentIdentity + (int) $hasAdminIdentity + (int) $hasOfficeIdentity > 1) {
    $_SESSION = [];
    session_regenerate_id(true);
}

header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/student-setup-guard.php';
knsEnforceStudentSetup();

function currentStudentId(): ?int
{
    $studentId = $_SESSION['student_id'] ?? null;
    return is_int($studentId) && $studentId > 0 && !isset($_SESSION['admin_id']) && !isset($_SESSION['office_id'])
        ? $studentId : null;
}

function requireStudent(): void
{
    if (currentStudentId() === null) {
        header('Location: ../login.php');
        exit;
    }
}

function currentOfficeId(): ?int
{
    $id = $_SESSION['office_id'] ?? null;
    return is_int($id) && $id > 0 && !isset($_SESSION['admin_id']) && !isset($_SESSION['student_id'])
        && in_array($_SESSION['office_role'] ?? null, ['Registrar', 'OSA'], true) ? $id : null;
}

function knsBeginAccountSession(): void
{
    global $knsTab;
    if ($knsTab !== '') {
        // A new login must not replace an account in a tab sharing the old URL.
        session_write_close();
        $knsTab = bin2hex(random_bytes(16));
        session_name('kns_student_portal_' . $knsTab);
        session_id('');
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION = [];
}
