<?php
// Optional daily task; request-time checks also enforce calendar boundaries.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/student-term-enrollments.php';
if (!knsAcademicTermsInstalled($pdo)) { fwrite(STDERR, "Apply the academic-term migration first.\n"); exit(1); }
knsSyncTermAccounts($pdo);
echo "Academic-term account dates synchronized.\n";
