<?php
// Apache routes the existing student HTML fragment URLs through this guard.
require __DIR__ . '/session.php';
if (currentStudentId() === null) {
    header('Location: ' . knsPortalUrl('login.php'), true, 303);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$fragments = ['module/module-results.html', 'module/request.html', 'request/request.html',
    'request/result-request.html', 'request/request-status.html'];
foreach ($fragments as $fragment) {
    if ($path === knsPortalUrl($fragment)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(dirname(__DIR__, 2) . '/' . $fragment);
        exit;
    }
}
http_response_code(404);
