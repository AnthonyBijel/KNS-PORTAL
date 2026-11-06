<?php
require_once __DIR__ . '/../auth/staff-access.php';
require_once __DIR__ . '/../academic-term-common.php';
require_once __DIR__ . '/../student-term-enrollments.php';
require __DIR__ . '/../../db.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$account = knsRequireStaffAccess($pdo, 'results', '../../login.php');
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        throw new KnsAcademicTermError('Use GET to view terms or POST to save a term.', 405);
    }
    if (!knsAcademicTermsInstalled($pdo)) throw new KnsAcademicTermError('Academic term management is not installed. Ask the administrator to apply the academic-term migration.', 503);
    $service = new KnsAcademicTerms($pdo);
    $message = '';
    if ($method === 'POST') {
        $csrf = $_POST['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) throw new KnsAcademicTermError('The form expired. Reopen Manage Terms and try again.', 403);
        $action = $_POST['action'] ?? 'schedule';
        if ($action === 'update') {
            $service->update($_POST, (int) $account['id']);
            $message = 'Academic term updated after password confirmation.';
        } elseif ($action === 'schedule') {
            if ($account['role'] !== 'Administrator') throw new KnsAcademicTermError('Only the Administrator can schedule academic terms.', 403);
            $service->schedule($_POST, (int) $account['id']);
            $message = 'Academic term saved. Earlier terms and their records remain in history.';
        } else {
            throw new KnsAcademicTermError('Choose a valid academic term action.');
        }
    }
    if ($method === 'GET' && isset($_GET['term_id'])) {
        $id = $_GET['term_id'];
        $page = $_GET['page'] ?? '1';
        $kind = $_GET['kind'] ?? 'enrollments';
        if (!is_string($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !is_string($page) || filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !is_string($kind)) throw new KnsAcademicTermError('Choose a valid term and history page.');
        $result = $service->records((int) $id, $kind, (int) $page);
    } else {
        knsSyncTermAccounts($pdo);
        $result = $service->calendar();
    }
    echo json_encode(['ok' => true, 'message' => $message, 'can_schedule' => $account['role'] === 'Administrator', 'can_edit' => in_array($account['role'], ['Administrator', 'Registrar'], true), 'csrf' => $_SESSION['csrf']] + $result,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (KnsAcademicTermError $error) {
    http_response_code($error->status);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Academic term operation failed (' . get_class($error) . ').');
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to complete the term request. Please try again.']);
}
