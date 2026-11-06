<?php
require_once __DIR__ . '/../auth/staff-access.php';
require_once __DIR__ . '/student-list-export-common.php';
require __DIR__ . '/../../db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
knsRequireStaffAccess($pdo, 'results', '../../login.php');
$csrf = $_SESSION['csrf'];
session_write_close();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $service = new KnsStudentListExport($pdo);
    if ($method === 'GET') {
        echo json_encode(['ok' => true, 'cohorts' => $service->cohorts(), 'csrf' => $csrf], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        throw new KnsStudentListExportError('Use GET for filter options or POST to preview and download.', 405);
    }
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($csrf, $token)) throw new KnsStudentListExportError('The form expired. Reopen Download List and try again.', 403);
    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['preview', 'download'], true)) throw new KnsStudentListExportError('Choose preview or download.');
    $filters = $service->filters($_POST);
    $students = $service->students($filters);
    $signature = knsStudentListSignature($filters, $students, $csrf);
    if ($action === 'preview') {
        echo json_encode(['ok' => true, 'students' => $students, 'total' => count($students), 'signature' => $signature, 'csrf' => $csrf], JSON_THROW_ON_ERROR);
        exit;
    }
    $reviewed = $_POST['signature'] ?? null;
    if (!is_string($reviewed) || !hash_equals($signature, $reviewed)) {
        throw new KnsStudentListExportError('The selection or student records changed. Preview the list again before downloading.', 409);
    }
    if (!$students) throw new KnsStudentListExportError('No students match these filters. Choose another group.');
    require_once __DIR__ . '/../../vendor/autoload.php';
    $book = knsStudentListWorkbook($students, $filters);
    // Finish generating the workbook before sending a successful download response.
    ob_start();
    try {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        $contents = ob_get_contents();
    } finally {
        ob_end_clean();
        $book->disconnectWorksheets();
    }
    $course = substr(preg_replace('/[^a-zA-Z0-9-]+/', '-', $filters['course']), 0, 40);
    $section = substr(preg_replace('/[^a-zA-Z0-9-]+/', '-', $filters['section']), 0, 40);
    $filename = 'KNS_Students_' . $filters['academic_year'] . '_' . $course . '_Year-' . $filters['year_level'] . '_' . $section . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
} catch (KnsStudentListExportError $error) {
    http_response_code($error->status);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Student list export failed (' . get_class($error) . ').');
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to prepare the student list. Please try again.']);
}
