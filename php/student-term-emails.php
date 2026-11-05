<?php
// Durable outbox: no addresses, message bodies, passwords, or transport errors are stored.
function knsQueueTermEmail(PDO $pdo, array $student, array $term, string $inactive, string $expiry): void
{
    $valid = filter_var(trim((string) ($student['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
    $statement = $pdo->prepare('INSERT IGNORE INTO student_term_emails
        (student_id, term_id, inactivated_at, expires_at, delivery_status, failure_code, next_attempt_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$student['id'], $term['id'], $inactive, $expiry, $valid ? 'queued' : 'failed',
        $valid ? null : 'no_valid_email', $valid ? null : gmdate('Y-m-d H:i:s', time() + 3600)]);
}

function knsTermWarningMessage(array $student, array $term, array $job, ?array $future, array $school): string
{
    $name = trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'] ?? '', $student['last_name']])));
    $message = "Hello $name,\n\n"
        . "Academic year: {$term['academic_year']}\nSemester: {$term['semester']}\n"
        . "Official term end date: {$term['official_end_date']}\n"
        . 'Term inactivation: ' . knsTermLocalDate($job['inactivated_at']) . " Asia/Manila\n"
        . 'Three-calendar-month expiry: ' . knsTermLocalDate($job['expires_at']) . " Asia/Manila\n\n";
    if ($future) {
        $message .= "Your qualifying Active enrollment for {$future['academic_year']}, {$future['semester']} is already recorded. "
            . "Active status is expected on {$future['start_date']} at 00:00:00 Asia/Manila. "
            . "The pending expiry above has been cancelled. Full portal access continues while this approved enrollment is pending.\n\n";
    } else {
        $message .= "Your account is term-inactive, with full access to all normal student portal features during the three-month grace period. "
            . 'Access ends at exactly ' . knsTermLocalDate($job['expires_at']) . " Asia/Manila unless a qualifying new enrollment is recorded.\n\n";
    }
    $message .= "Please complete re-enrollment and contact the Registrar or Student Services so your new enrollment is uploaded using your existing student_number. "
        . "If you already re-enrolled, confirm that the upload was successfully recorded.\n\n"
        . "Without a qualifying new enrollment by the deadline, student login will be disabled and the account removed from active student access. "
        . "Historical academic, enrollment, request, and message records will be preserved using a non-login identity reference. "
        . "Credentials and live profile/contact data will be removed after expiry.\n";
    $contacts = [];
    foreach (['school_name'=>'School', 'school_email'=>'Email', 'school_contact'=>'Phone', 'school_address'=>'Address'] as $field=>$label) {
        if (trim((string) ($school[$field] ?? '')) !== '') $contacts[] = $label . ': ' . trim($school[$field]);
    }
    if ($contacts) $message .= "\n" . implode("\n", $contacts) . "\n";
    return $message;
}

function knsProcessTermEmails(PDO $pdo, ?int $studentId = null, ?callable $deliver = null, int $limit = 100): int
{
    if ($pdo->inTransaction()) return 0; // Never send before the status/import transaction commits.
    $statement = $pdo->prepare("SELECT id FROM student_term_emails WHERE delivery_status IN ('queued','failed','sending')
        AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())"
        . ($studentId !== null ? ' AND student_id = ?' : '') . ' ORDER BY id LIMIT ' . max(1, min(1000, $limit)));
    $statement->execute($studentId !== null ? [$studentId] : []);
    $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
    $processed = 0;
    foreach ($ids as $id) {
        $lock = 'kns-term-email-' . $id;
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) continue;
        try {
            $statement = $pdo->prepare('SELECT * FROM student_term_emails WHERE id = ?');
            $statement->execute([$id]); $job = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$job || !in_array($job['delivery_status'], ['queued','failed','sending'], true)) continue;
            if ($job['delivery_status'] === 'sending') {
                // A crashed worker may already have handed the message to SMTP. Never blindly retry.
                $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'uncertain',
                    failure_code = 'delivery_unconfirmed' WHERE id = ? AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
                $statement->execute([$id]);
                continue;
            }
            $statement = $pdo->prepare('SELECT * FROM students WHERE id = ?');
            $statement->execute([$job['student_id']]); $student = $statement->fetch(PDO::FETCH_ASSOC);
            [$current, $future] = knsApprovedTermEnrollments($pdo, (int) $job['student_id'], knsAcademicTermToday());
            if (!$student || !knsStudentMayAccess($student) || $student['status'] !== 'Inactive'
                || empty($student['term_inactivated_at']) || $current !== null) {
                $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'cancelled', failure_code = NULL WHERE id = ?");
                $statement->execute([$id]); continue;
            }
            $email = trim((string) $student['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'failed', failure_code = 'no_valid_email',
                    next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = ?");
                $statement->execute([$id]); continue;
            }
            $statement = $pdo->prepare('SELECT * FROM academic_terms WHERE id = ?');
            $statement->execute([$job['term_id']]); $term = $statement->fetch(PDO::FETCH_ASSOC);
            $school = $pdo->query("SELECT school_name, school_email, school_contact, school_address FROM admin_account
                WHERE role = 'Administrator' AND is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
            // Claim durably before SMTP; named lock serializes workers without a database transaction.
            $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'sending', claimed_at = UTC_TIMESTAMP(),
                attempts = attempts + 1, failure_code = NULL WHERE id = ? AND delivery_status IN ('queued','failed')");
            $statement->execute([$id]);
            if ($statement->rowCount() !== 1) continue;
            $processed++;
            try {
                if ($deliver === null) require_once __DIR__ . '/auth/password_reset_common.php';
                $sender = $deliver ?? 'knsDeliverMessage';
                $sender('email', $email, 'KNS Portal term-end enrollment and account notice',
                    knsTermWarningMessage($student, $term, $job, $future, $school),
                    '<kns-term-' . $job['student_id'] . '-' . $job['term_id'] . '@kns-portal.local>');
            } catch (Throwable $error) {
                $uncertain = $error instanceof KnsMailDeliveryUncertain;
                $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = ?, failure_code = ?,
                    next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = ?");
                $statement->execute([$uncertain ? 'uncertain' : 'failed',
                    $uncertain ? 'delivery_unconfirmed' : 'delivery_failed', $id]);
                continue; // Do not log provider errors or block account sync/login.
            }
            $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'sent', sent_at = UTC_TIMESTAMP(),
                next_attempt_at = NULL, failure_code = NULL WHERE id = ?");
            $statement->execute([$id]);
        } catch (Throwable $error) {
            error_log('Term-end email processing unavailable. Check the delivery status in Students List.');
        } finally {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $statement->execute([$lock]);
        }
    }
    return $processed;
}

function knsAfterTermCommit(PDO $pdo, ?int $studentId = null): void
{
    if ($pdo->inTransaction()) return;
    try { knsProcessTermPhotoCleanup($pdo); knsProcessTermEmails($pdo, $studentId); }
    catch (Throwable $error) { error_log('Term-end background processing is pending. Run the scheduled sync.'); }
}
