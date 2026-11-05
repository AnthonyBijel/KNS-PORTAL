<?php
require_once __DIR__ . '/academic-term-common.php';

function knsEnrollmentSnapshot(PDO $pdo, int $studentId, array $profile): void
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
        return;
    }
    $statement = $pdo->prepare('INSERT INTO student_enrollments (student_id, term_id, academic_year, semester, course, year_level, section, enrollment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$studentId, $termId, ...$snapshot]);
}

function knsCaptureImportedEnrollment(PDO $pdo, int $studentId, ?array $existing, array $saved, bool $explicitStatus): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    if ($existing !== null) {
        // A term-driven deactivation is access state, not a new historical enrollment decision.
        $before = $existing;
        if ((int) ($before['term_auto_inactive'] ?? 0)) $before['status'] = 'Active';
        knsEnrollmentSnapshot($pdo, $studentId, $before);
        if (!$explicitStatus && (int) ($existing['term_auto_inactive'] ?? 0)) $saved['status'] = 'Active';
    }
    knsEnrollmentSnapshot($pdo, $studentId, $saved);
    // Explicit status choices are manual; subsequent date transitions must respect them.
    if ($explicitStatus) {
        $statement = $pdo->prepare('UPDATE students SET term_auto_inactive = 0 WHERE id = ?');
        $statement->execute([$studentId]);
    }
    knsSyncStudentTerm($pdo, $studentId);
}

function knsSyncStudentTerm(PDO $pdo, int $studentId, ?DateTimeImmutable $now = null): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM students WHERE id = ? FOR UPDATE');
        $statement->execute([$studentId]);
        $student = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$student || ($student['status'] !== 'Active' && !(int) $student['term_auto_inactive'])) {
            if ($ownsTransaction) $pdo->commit();
            return; // Keep manual Inactive and Graduated accounts blocked.
        }
        $statement = $pdo->prepare('SELECT e.*, t.start_date, t.official_end_date FROM student_enrollments e
            JOIN academic_terms t ON t.id = e.term_id WHERE e.student_id = ?
            AND NOT EXISTS (SELECT 1 FROM student_enrollments newer WHERE newer.student_id = e.student_id AND newer.term_id = e.term_id AND newer.id > e.id)
            ORDER BY t.start_date DESC, e.id DESC');
        $statement->execute([$studentId]);
        $enrollments = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$enrollments) {
            if ($ownsTransaction) $pdo->commit();
            return; // Unassigned legacy profiles have no invented expiry.
        }
        $today = knsAcademicTermToday($now);
        $active = null;
        $started = null;
        foreach ($enrollments as $enrollment) {
            if ($enrollment['start_date'] <= $today && $started === null) $started = $enrollment;
            if ($enrollment['enrollment_status'] === 'Active' && $enrollment['start_date'] <= $today && $today <= $enrollment['official_end_date']) {
                $active = $enrollment;
                break;
            }
        }
        $profile = $active ?? $started;
        // Future re-enrollment must not replace the still-current course/section.
        if ($profile === null) {
            $statement = $pdo->prepare('SELECT * FROM student_enrollments WHERE student_id = ? AND term_id IS NULL ORDER BY id ASC LIMIT 1');
            $statement->execute([$studentId]);
            $profile = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $status = $active !== null ? 'Active' : $student['status'];
        $automatic = $active !== null ? 0 : (int) $student['term_auto_inactive'];
        if ($active === null && knsAcademicTermConfig()['deactivate_after_term']) {
            $status = 'Inactive';
            $automatic = 1;
        }
        $values = ['status' => $status, 'term_auto_inactive' => $automatic];
        if ($profile !== null) foreach (['course', 'year_level', 'section', 'academic_year', 'semester'] as $field) $values[$field] = $profile[$field];
        $changes = [];
        foreach ($values as $key => $value) if ((string) ($student[$key] ?? '') !== (string) ($value ?? '')) $changes[$key] = $value;
        if ($changes) {
            $assignments = array_map(static fn ($field) => "`$field` = ?", array_keys($changes));
            $statement = $pdo->prepare('UPDATE students SET ' . implode(', ', $assignments) . ' WHERE id = ?');
            $statement->execute([...array_values($changes), $studentId]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function knsSyncStudentTermByNumber(PDO $pdo, string $number): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    $statement = $pdo->prepare('SELECT id FROM students WHERE student_number = ? LIMIT 1');
    $statement->execute([$number]);
    $id = $statement->fetchColumn();
    if ($id !== false) knsSyncStudentTerm($pdo, (int) $id);
}

function knsSyncTermAccounts(PDO $pdo): void
{
    if (!knsAcademicTermsInstalled($pdo)) return;
    $ids = $pdo->query('SELECT DISTINCT student_id FROM student_enrollments WHERE term_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) knsSyncStudentTerm($pdo, (int) $id);
}
