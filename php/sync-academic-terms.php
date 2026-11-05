<?php
// Daily CLI status/expiry/outbox processing. Never expose this as a web endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$syncCompleted = false;
// db.php can die on connection failure; Task Scheduler must still receive a nonzero result.
register_shutdown_function(static function () use (&$syncCompleted): void { if (!$syncCompleted) exit(1); });
require __DIR__ . '/student-term-enrollments.php';
try {
    require __DIR__ . '/../db.php';
    knsRequireEnrollmentActivation($pdo);
    $checked = knsSyncTermAccounts($pdo);
    knsProcessTermPhotoCleanup($pdo);
    knsProcessTermEmails($pdo);
    echo 'Academic-term account dates synchronized for ' . $checked . ' accounts on '
        . knsAcademicTermToday() . ' (' . knsAcademicTermConfig()['timezone'] . ").\n";
    echo 'Term-end email/cleanup status: ' . json_encode(knsTermDeliveryCounts($pdo)) . "\n";
    $syncCompleted = true;
} catch (Throwable $error) {
    fwrite(STDERR, "Academic-term synchronization failed. Check database access and the academic-term, activation, and 20261106_student_term_grace.sql migrations.\n");
    exit(1);
}
