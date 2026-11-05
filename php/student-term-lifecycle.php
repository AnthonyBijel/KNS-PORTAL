<?php
require_once __DIR__ . '/academic-term-common.php';

function knsTermNow(?DateTimeImmutable $now = null): DateTimeImmutable
{
    return ($now ?? new DateTimeImmutable('now', knsAcademicTermZone()))->setTimezone(knsAcademicTermZone());
}

function knsTermUtc(?DateTimeImmutable $now = null): string
{
    return knsTermNow($now)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

// Clamp to the last day of the target month instead of overflowing (e.g. Nov 30 -> Feb 28/29).
function knsTermGraceExpiry(DateTimeImmutable $inactive): DateTimeImmutable
{
    $inactive = $inactive->setTimezone(knsAcademicTermZone());
    $target = $inactive->modify('first day of this month')->modify('+3 months');
    return $target->setDate((int) $target->format('Y'), (int) $target->format('m'),
        min((int) $inactive->format('d'), (int) $target->format('t')));
}

function knsStudentMayAccess(?array $student, ?DateTimeImmutable $now = null): bool
{
    if (!$student || !empty($student['archived_at'])) return false;
    if ($student['status'] === 'Active') return true;
    return $student['status'] === 'Inactive' && (int) ($student['term_auto_inactive'] ?? 0)
        && !empty($student['term_inactivated_at'])
        && (empty($student['term_expires_at']) || $student['term_expires_at'] > knsTermUtc($now));
}

function knsStudentAccessSql(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Invalid SQL alias.');
    $p = $alias === '' ? '' : $alias . '.';
    return "({$p}archived_at IS NULL AND ({$p}status = 'Active' OR
        ({$p}status = 'Inactive' AND {$p}term_auto_inactive = 1 AND {$p}term_inactivated_at IS NOT NULL
        AND ({$p}term_expires_at IS NULL OR {$p}term_expires_at > UTC_TIMESTAMP()))))";
}

function knsTermLocalDate(?string $utc): string
{
    return $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(knsAcademicTermZone())->format('Y-m-d H:i:s') : '';
}

function knsStudentGraceNotice(array $student): string
{
    if ($student['status'] !== 'Inactive' || !(int) ($student['term_auto_inactive'] ?? 0) || empty($student['term_inactivated_at'])) return '';
    return !empty($student['term_expires_at'])
        ? 'Term inactive: full portal access until ' . knsTermLocalDate($student['term_expires_at']) . ' Asia/Manila. Ask the Registrar to upload your re-enrollment using the same student number.'
        : 'Your upcoming enrollment is recorded and the pending expiry is cancelled. Full portal access continues; Active status resumes on the saved term start date.';
}

function knsApprovedTermEnrollments(PDO $pdo, int $studentId, string $today): array
{
    $statement = $pdo->prepare("SELECT e.*, t.start_date, t.official_end_date FROM student_enrollments e
        JOIN academic_terms t ON t.id = e.term_id WHERE e.student_id = ? AND e.activation_authorized = 1
        AND e.enrollment_status = 'Active' AND t.official_end_date >= ?
        AND TRIM(e.course) <> '' AND e.year_level > 0 AND TRIM(e.section) <> ''
        ORDER BY t.start_date ASC, e.id DESC");
    $statement->execute([$studentId, $today]);
    $current = null; $future = null;
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $enrollment) {
        if ($enrollment['start_date'] <= $today && $current === null) $current = $enrollment;
        if ($enrollment['start_date'] > $today && $future === null) $future = $enrollment;
    }
    return [$current, $future];
}

// Keep FK targets and student-number joins, but remove the live account's credentials/profile.
function knsArchiveTermStudent(PDO $pdo, array $student, string $utc): void
{
    $statement = $pdo->prepare('UPDATE student_term_emails SET photo_cleanup_path = ? WHERE student_id = ? AND term_id = ?');
    $statement->execute([$student['profile_photo'], $student['id'], $student['term_inactive_term_id']]);
    $statement = $pdo->prepare("UPDATE students SET password_hash = NULL, first_name = '', middle_name = NULL,
        last_name = '', suffix = NULL, course = '', year_level = 0, section = '', academic_year = NULL, semester = NULL,
        email = NULL, contact_number = NULL, address = NULL, birth_date = NULL, guardian_name = NULL,
        guardian_contact = NULL, profile_photo = NULL, requires_password_change = 0,
        temporary_password_expires_at = NULL, status = 'Inactive', term_auto_inactive = 0,
        term_inactivated_at = NULL, term_expires_at = NULL, term_inactive_term_id = NULL, archived_at = ?
        WHERE id = ? AND archived_at IS NULL");
    $statement->execute([$utc, $student['id']]);
    $statement = $pdo->prepare('DELETE FROM password_reset_tokens WHERE student_id = ?');
    $statement->execute([$student['id']]);
    $statement = $pdo->prepare("UPDATE student_term_emails SET delivery_status = 'cancelled', failure_code = NULL
        WHERE student_id = ? AND delivery_status IN ('queued','failed')");
    $statement->execute([$student['id']]);
}

function knsProcessTermPhotoCleanup(PDO $pdo): void
{
    // Only recorded current photos owned by the existing random-name upload workflow.
    foreach ($pdo->query('SELECT id, photo_cleanup_path FROM student_term_emails WHERE photo_cleanup_path IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $job) {
        $path = $job['photo_cleanup_path'];
        if (!preg_match('~^uploads/students/student_[a-f0-9]{40}\.(png|jpg|webp)$~D', $path)) continue;
        $root = realpath(__DIR__ . '/../uploads/students');
        $file = $root ? $root . DIRECTORY_SEPARATOR . basename($path) : null;
        if ($file && is_file($file)) {
            $resolved = realpath($file);
            if (!$resolved || strcasecmp(dirname($resolved), $root) !== 0) continue;
            $statement = $pdo->prepare('SELECT id FROM students WHERE profile_photo = ? LIMIT 1');
            $statement->execute([$path]);
            if ($statement->fetchColumn() !== false || !@unlink($resolved)) continue;
        }
        $statement = $pdo->prepare('UPDATE student_term_emails SET photo_cleanup_path = NULL WHERE id = ? AND photo_cleanup_path = ?');
        $statement->execute([$job['id'], $path]);
    }
}

function knsTermDeliveryCounts(PDO $pdo): array
{
    $counts = ['queued'=>0, 'sending'=>0, 'sent'=>0, 'failed'=>0, 'uncertain'=>0, 'cancelled'=>0, 'photo_cleanup_pending'=>0];
    foreach ($pdo->query('SELECT delivery_status, COUNT(*) AS amount FROM student_term_emails GROUP BY delivery_status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['delivery_status']] = (int) $row['amount'];
    }
    $counts['photo_cleanup_pending'] = (int) $pdo->query('SELECT COUNT(*) FROM student_term_emails WHERE photo_cleanup_path IS NOT NULL')->fetchColumn();
    return $counts;
}
