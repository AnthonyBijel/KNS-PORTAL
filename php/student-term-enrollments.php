<?php
require_once __DIR__ . '/academic-term-common.php';
require_once __DIR__ . '/student-term-lifecycle.php';
require_once __DIR__ . '/student-term-emails.php';

function knsRequireEnrollmentActivation(PDO $pdo): void
{
    if (!knsAcademicTermsInstalled($pdo)) {
        throw new RuntimeException('Import 20261105_academic_term_management.sql and 20261105_student_term_activation.sql before using term enrollment.');
    }
    static $connections;
    $connections ??= new WeakMap();
    if (isset($connections[$pdo])) return;
    try {
        $pdo->query('SELECT activation_authorized FROM student_enrollments LIMIT 0');
        $pdo->query('SELECT term_inactivated_at, term_expires_at, term_inactive_term_id, archived_at FROM students LIMIT 0');
        $pdo->query('SELECT id FROM student_term_emails LIMIT 0');
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S22', '42S02'], true)) {
            throw new RuntimeException('Import 20261105_student_term_activation.sql and 20261106_student_term_grace.sql before using term enrollment.', 0, $error);
        }
        throw $error;
    }
    $connections[$pdo] = true;
}

// Validate the supplied row, never details inherited from the existing account.
function knsValidateImportedEnrollment(PDO $pdo, array $values): array
{
    if (($values['status'] ?? '') !== 'Active') return $values;
    knsRequireEnrollmentActivation($pdo);
    foreach (['academic_year', 'semester', 'course', 'year_level', 'section'] as $field) {
        if (!isset($values[$field]) || trim((string) $values[$field]) === '') {
            throw new InvalidArgumentException('Active enrollment requires academic_year, semester, course, year_level, and section in this row.');
        }
    }
    try {
        $values['academic_year'] = knsAcademicYear($values['academic_year']);
        $values['semester'] = knsAcademicSemester($values['semester']);
    } catch (KnsAcademicTermError $error) {
        throw new InvalidArgumentException($error->getMessage(), 0, $error);
    }
    if (!preg_match('/^[0-9]{1,3}$/D', (string) $values['year_level'])
        || (int) $values['year_level'] < 1 || (int) $values['year_level'] > 255) {
        throw new InvalidArgumentException('Active enrollment requires a year_level from 1 to 255.');
    }
    $statement = $pdo->prepare('SELECT id FROM academic_terms WHERE academic_year = ? AND semester = ? AND official_end_date >= ?');
    $statement->execute([$values['academic_year'], $values['semester'], knsAcademicTermToday()]);
    if ($statement->fetchColumn() === false) {
        throw new InvalidArgumentException('Active enrollment requires a saved current or upcoming academic term. Save its dates in Manage Terms first.');
    }
    return $values;
}

function knsEnrollmentSnapshot(PDO $pdo, int $studentId, array $profile, bool $authorize = false): void
{
    $year = $profile['academic_year'] ?? null;
    $semester = $profile['semester'] ?? null;
    try { if ($year !== null && $year !== '') $year = knsAcademicYear($year); } catch (KnsAcademicTermError $error) {}
    try { if ($semester !== null && $semester !== '') $semester = knsAcademicSemester($semester); } catch (KnsAcademicTermError $error) {}
    $year = $year === '' ? null : $year;
    $semester = $semester === '' ? null : $semester;
    $termId = null;
    if ($year !== null && $semester !== null) {
        $statement = $pdo->prepare('SELECT id FROM academic_terms WHERE academic_year = ? AND semester = ?');
        $statement->execute([$year, $semester]);
        $termId = $statement->fetchColumn() ?: null;
    }
    $snapshot = [$year, $semester, $profile['course'], (int) $profile['year_level'], $profile['section'], $profile['status']];
    $statement = $pdo->prepare('SELECT e.* FROM student_enrollments e WHERE e.student_id = ? AND '
        . knsTermYearSql('e') . ' <=> ? AND ' . knsTermSemesterSql('e') . ' <=> ? ORDER BY e.id DESC LIMIT 1');
    $statement->execute([$studentId, $year, $semester]);
    $previous = $statement->fetch(PDO::FETCH_ASSOC);
    if ($previous) {
        try { if ($previous['academic_year'] !== null) $previous['academic_year'] = knsAcademicYear($previous['academic_year']); } catch (KnsAcademicTermError $error) {}
        try { if ($previous['semester'] !== null) $previous['semester'] = knsAcademicSemester($previous['semester']); } catch (KnsAcademicTermError $error) {}
    }
    if ($previous && [$previous['academic_year'], $previous['semester'], $previous['course'], (int) $previous['year_level'], $previous['section'], $previous['enrollment_status']] === $snapshot) {
        if ($termId !== null && $previous['term_id'] === null) {
            $statement = $pdo->prepare('UPDATE student_enrollments SET term_id = ? WHERE id = ? AND term_id IS NULL');
            $statement->execute([$termId, $previous['id']]);
        }
        if ($authorize) {
            $statement = $pdo->prepare('UPDATE student_enrollments SET activation_authorized = 1 WHERE id = ?');
            $statement->execute([$previous['id']]);
        }
        return;
    }
    $statement = $pdo->prepare('INSERT INTO student_enrollments (student_id, term_id, academic_year, semester, course, year_level, section, enrollment_status, activation_authorized) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$studentId, $termId, ...$snapshot, (int) $authorize]);
}

function knsCaptureImportedEnrollment(PDO $pdo, int $studentId, ?array $existing, array $saved, bool $explicitStatus): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    knsRequireEnrollmentActivation($pdo);
    if ($existing !== null && empty($existing['archived_at'])) {
        $before = $existing;
        if ((int) ($before['term_auto_inactive'] ?? 0)) $before['status'] = 'Active';
        knsEnrollmentSnapshot($pdo, $studentId, $before);
        if (!$explicitStatus && (int) ($existing['term_auto_inactive'] ?? 0)) $saved['status'] = 'Active';
    }
    $authorize = $explicitStatus && $saved['status'] === 'Active';
    knsEnrollmentSnapshot($pdo, $studentId, $saved, $authorize);
    if ($explicitStatus && !$authorize) {
        $statement = $pdo->prepare('UPDATE student_enrollments SET activation_authorized = 0 WHERE student_id = ? AND activation_authorized = 1');
        $statement->execute([$studentId]);
        $statement = $pdo->prepare('UPDATE students SET term_auto_inactive = 0, term_inactivated_at = NULL,
            term_expires_at = NULL, term_inactive_term_id = NULL WHERE id = ?');
        $statement->execute([$studentId]);
        $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'cancelled', failure_code = NULL
            WHERE student_id = ? AND delivery_status IN ('queued','failed')");
        $statement->execute([$studentId]);
    } elseif ($authorize) {
        $statement = $pdo->prepare('SELECT start_date FROM academic_terms WHERE academic_year = ? AND semester = ?');
        $statement->execute([$saved['academic_year'], $saved['semester']]);
        $future = $statement->fetchColumn() > knsAcademicTermToday();
        if ($future) {
            $keepCurrent = $existing && $existing['status'] === 'Active' && empty($existing['archived_at']);
            $statement = $pdo->prepare('UPDATE students SET archived_at = NULL, status = ?, term_auto_inactive = ?,
                term_expires_at = NULL WHERE id = ?');
            $statement->execute([$keepCurrent ? 'Active' : 'Inactive', $keepCurrent ? 0 : 1, $studentId]);
            if ($existing && empty($existing['archived_at'])) {
                $statement = $pdo->prepare('UPDATE students SET course = ?, year_level = ?, section = ?, academic_year = ?, semester = ? WHERE id = ?');
                $statement->execute([$existing['course'], $existing['year_level'], $existing['section'], $existing['academic_year'], $existing['semester'], $studentId]);
            }
        } else {
            $statement = $pdo->prepare("UPDATE students SET archived_at = NULL, status = 'Active', term_auto_inactive = 0,
                term_inactivated_at = NULL, term_expires_at = NULL, term_inactive_term_id = NULL WHERE id = ?");
            $statement->execute([$studentId]);
            $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'cancelled', failure_code = NULL
                WHERE student_id = ? AND delivery_status IN ('queued','failed')");
            $statement->execute([$studentId]);
        }
    }
    knsSyncStudentTerm($pdo, $studentId);
}

function knsSyncStudentTerm(PDO $pdo, int $studentId, ?DateTimeImmutable $now = null, bool $processDelivery = true): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    knsRequireEnrollmentActivation($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM students WHERE id = ? FOR UPDATE');
        $statement->execute([$studentId]); $student = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$student || !empty($student['archived_at']) || ($student['status'] !== 'Active'
            && !($student['status'] === 'Inactive' && (int) $student['term_auto_inactive']))) {
            if ($ownsTransaction) $pdo->commit();
            return;
        }
        $today = knsAcademicTermToday($now); $utc = knsTermUtc($now);
        [$active, $future] = knsApprovedTermEnrollments($pdo, $studentId, $today);
        if ($active !== null) {
            $statement = $pdo->prepare("UPDATE students SET status = 'Active', term_auto_inactive = 0,
                term_inactivated_at = NULL, term_expires_at = NULL, term_inactive_term_id = NULL,
                course = ?, year_level = ?, section = ?, academic_year = ?, semester = ? WHERE id = ?");
            $statement->execute([$active['course'], $active['year_level'], $active['section'], $active['academic_year'], $active['semester'], $studentId]);
            $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'cancelled', failure_code = NULL
                WHERE student_id = ? AND delivery_status IN ('queued','failed')");
            $statement->execute([$studentId]);
        } elseif (knsAcademicTermConfig()['deactivate_after_term']) {
            $statement = $pdo->prepare('SELECT * FROM academic_terms WHERE official_end_date < ? ORDER BY official_end_date DESC, id DESC LIMIT 1');
            $statement->execute([$today]); $ended = $statement->fetch(PDO::FETCH_ASSOC);
            // A recorded enrollment may have started and ended between two missed syncs.
            $statement = $pdo->prepare("SELECT t.* FROM student_enrollments e JOIN academic_terms t ON t.id = e.term_id
                WHERE e.student_id = ? AND e.activation_authorized = 1 AND e.enrollment_status = 'Active'
                AND t.official_end_date < ? ORDER BY t.official_end_date DESC LIMIT 1");
            $statement->execute([$studentId, $today]); $enrolledEnd = $statement->fetch(PDO::FETCH_ASSOC);
            $newCycle = $enrolledEnd && $enrolledEnd['id'] != ($student['term_inactive_term_id'] ?? null)
                && (empty($student['term_inactivated_at']) || $enrolledEnd['start_date'] > substr(knsTermLocalDate($student['term_inactivated_at']), 0, 10));
            if ($newCycle) $ended = $enrolledEnd;
            $startGrace = $ended && (empty($student['term_inactivated_at']) || $newCycle)
                && ($student['status'] === 'Active' || $newCycle || $future === null);
            if ($startGrace) {
                $inactive = (new DateTimeImmutable($ended['official_end_date'] . ' 00:00:00', knsAcademicTermZone()))->modify('+1 day');
                $student['term_inactivated_at'] = knsTermUtc($inactive);
                $expiry = knsTermUtc(knsTermGraceExpiry($inactive));
                $student['term_expires_at'] = $future ? null : $expiry;
                $student['term_inactive_term_id'] = $ended['id'];
                knsQueueTermEmail($pdo, $student, $ended, $student['term_inactivated_at'], $expiry);
            } elseif ($future && !empty($student['term_inactivated_at'])) {
                $student['term_expires_at'] = null; // Only a previously authorized import can cancel expiry.
            }
            if (!empty($student['term_inactivated_at'])) {
                $statement = $pdo->prepare("UPDATE students SET status = 'Inactive', term_auto_inactive = 1,
                    term_inactivated_at = ?, term_expires_at = ?, term_inactive_term_id = ? WHERE id = ?");
                $statement->execute([$student['term_inactivated_at'], $student['term_expires_at'], $student['term_inactive_term_id'], $studentId]);
                if ($student['term_expires_at'] !== null && $student['term_expires_at'] <= $utc) {
                    knsArchiveTermStudent($pdo, $student, $utc);
                }
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if ($ownsTransaction && $processDelivery && !(defined('KNS_TERM_EMAIL_DEFER') && KNS_TERM_EMAIL_DEFER)) knsAfterTermCommit($pdo, $studentId);
}

function knsSyncStudentTermByNumber(PDO $pdo, string $number): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    $statement = $pdo->prepare('SELECT id FROM students WHERE student_number = ? LIMIT 1');
    $statement->execute([$number]);
    $id = $statement->fetchColumn();
    if ($id !== false) knsSyncStudentTerm($pdo, (int) $id);
}

function knsSyncTermAccounts(PDO $pdo, ?DateTimeImmutable $now = null): int
{
    if (!knsAcademicTermsInstalled($pdo)) return 0;
    knsRequireEnrollmentActivation($pdo);
    $ids = $pdo->query("SELECT id FROM students WHERE archived_at IS NULL AND (status = 'Active' OR (status = 'Inactive' AND term_auto_inactive = 1))")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) knsSyncStudentTerm($pdo, (int) $id, $now, false);
    return count($ids);
}
