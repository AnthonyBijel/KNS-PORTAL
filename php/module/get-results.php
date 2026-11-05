<?php
ini_set('display_errors', '0');
require __DIR__ . '/../auth/session.php';
header('Content-Type: application/json; charset=utf-8');

function academicResultsReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$studentId = currentStudentId();
if ($studentId === null) {
    academicResultsReply(401, ['ok' => false, 'message' => 'Your session expired. Please sign in again.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    academicResultsReply(405, ['ok' => false, 'message' => 'Use GET to view academic results.']);
}

$pdo = null;
try {
    // db.php prints its PDO error before exiting on connection failure. Keep those
    // details out of the API while reusing the project's existing connection.
    ob_start(static function (string $output, int $phase): string {
        if ($phase & PHP_OUTPUT_HANDLER_CLEAN) {
            return '';
        }
        http_response_code(500);
        return json_encode(['ok' => false, 'message' => 'Academic results are temporarily unavailable. Please try again.']);
    });
    try {
        require __DIR__ . '/../../db.php';
    } finally {
        ob_end_clean();
    }
    // Read a consistent snapshot of both tables if an admin uploads concurrently.
    $pdo->beginTransaction();
    $studentQuery = $pdo->prepare('SELECT student_number, course FROM students WHERE id = ? AND ' . knsStudentAccessSql() . ' LIMIT 1');
    $studentQuery->execute([$studentId]);
    $student = $studentQuery->fetch(PDO::FETCH_ASSOC);
    if ($student === false) {
        $pdo->rollBack();
        unset($_SESSION['student_id'], $_SESSION['student_photo_feedback']);
        academicResultsReply(401, ['ok' => false, 'message' => 'Your student account is unavailable. Please sign in again.']);
    }
    $studentNumber = $student['student_number'];
    // The target student is resolved only from the authenticated session, never GET/POST.
    $gradesQuery = $pdo->prepare(
        'SELECT academic_year, semester, subject_code, subject_name,
                prelim_grade, midterm_grade, final_grade, overall_grade
         FROM subject_grades WHERE student_number = ? AND BINARY student_number = BINARY ? ORDER BY updated_at DESC, id DESC'
    );
    $gradesQuery->execute([$studentNumber, $studentNumber]);
    $grades = $gradesQuery->fetchAll(PDO::FETCH_ASSOC);
    $assessmentsQuery = $pdo->prepare(
        'SELECT academic_year, semester, grading_period, subject_code, subject_name,
                assessment_type, assessment_name, score, max_score, date_posted, remarks
         FROM grade_assessments WHERE student_number = ? AND BINARY student_number = BINARY ? ORDER BY date_posted DESC, id DESC'
    );
    $assessmentsQuery->execute([$studentNumber, $studentNumber]);
    $assessments = $assessmentsQuery->fetchAll(PDO::FETCH_ASSOC);
    $pdo->commit();
    academicResultsReply(200, ['ok' => true, 'course' => $student['course'], 'subject_grades' => $grades, 'assessments' => $assessments]);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Academic results unavailable: ' . get_class($error)
        . ($error instanceof PDOException ? ' (SQLSTATE ' . $error->getCode() . ')' : ''));
    academicResultsReply(500, ['ok' => false, 'message' => 'Unable to load academic results. Please reopen Academic and try again.']);
}
