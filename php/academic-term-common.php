<?php
const KNS_TERM_SEMESTERS = ['1st Semester', '2nd Semester', 'Summer'];

final class KnsAcademicTermError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}

function knsAcademicTermConfig(): array
{
    static $config;
    return $config ??= require __DIR__ . '/../config/academic-terms.php';
}

function knsAcademicTermZone(): DateTimeZone
{
    return new DateTimeZone(knsAcademicTermConfig()['timezone']);
}

function knsAcademicTermToday(?DateTimeImmutable $now = null): string
{
    return ($now ?? new DateTimeImmutable('now', knsAcademicTermZone()))->setTimezone(knsAcademicTermZone())->format('Y-m-d');
}

function knsAcademicYear(string $value): string
{
    if (!preg_match('/^(\d{4})\s*[-\/\x{2013}\x{2014}]\s*(\d{4})$/u', trim($value), $match)
        || (int) $match[1] < 1000 || (int) $match[2] !== (int) $match[1] + 1) {
        throw new KnsAcademicTermError('Academic Year must contain consecutive years, such as YYYY-YYYY.');
    }
    return $match[1] . '-' . $match[2];
}

function knsAcademicSemester(string $value): string
{
    $key = strtolower(preg_replace('/[\s_-]+/', '', trim($value)));
    return match ($key) {
        '1', '1st', '1stsem', '1stsemester', 'firstsemester', 'semester1' => '1st Semester',
        '2', '2nd', '2ndsem', '2ndsemester', 'secondsemester', 'semester2' => '2nd Semester',
        'summer' => 'Summer',
        default => throw new KnsAcademicTermError('Choose 1st Semester, 2nd Semester, or Summer.'),
    };
}

function knsAcademicTermDate(string $value, string $label): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) throw new KnsAcademicTermError($label . ' must be a valid date (YYYY-MM-DD).');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, knsAcademicTermZone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1000
        || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        throw new KnsAcademicTermError($label . ' must be a valid date (YYYY-MM-DD).');
    }
    return $value;
}

function knsAcademicTermStatus(array $term, ?DateTimeImmutable $now = null): string
{
    $today = knsAcademicTermToday($now);
    if ($today < $term['start_date']) return 'Scheduled';
    return $today <= $term['official_end_date'] ? 'Active' : 'Completed';
}

function knsAcademicTermsInstalled(PDO $pdo): bool
{
    static $connections;
    $connections ??= new WeakMap();
    if (isset($connections[$pdo])) return $connections[$pdo];
    try {
        $pdo->query('SELECT term_auto_inactive FROM students LIMIT 0');
        $pdo->query('SELECT id FROM academic_terms LIMIT 0');
        $pdo->query('SELECT id FROM student_enrollments LIMIT 0');
        return $connections[$pdo] = true;
    } catch (PDOException $error) {
        if (in_array($error->getCode(), ['42S02', '42S22'], true)) return $connections[$pdo] = false;
        throw $error;
    }
}

// Read legacy labels without rewriting existing grade/assessment records.
function knsTermYearSql(string $alias): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE((TRIM($alias.academic_year) COLLATE utf8mb4_unicode_ci), ' ', ''), '/', '-'), (CONVERT(0xE28093 USING utf8mb4) COLLATE utf8mb4_unicode_ci), '-'), (CONVERT(0xE28094 USING utf8mb4) COLLATE utf8mb4_unicode_ci), '-')";
}

function knsTermSemesterSql(string $alias): string
{
    $key = "LOWER(REPLACE(REPLACE(REPLACE((TRIM($alias.semester) COLLATE utf8mb4_unicode_ci), ' ', ''), '_', ''), '-', ''))";
    return "CASE WHEN $key IN ('1','1st','1stsem','1stsemester','firstsemester','semester1') THEN '1st Semester'
        WHEN $key IN ('2','2nd','2ndsem','2ndsemester','secondsemester','semester2') THEN '2nd Semester'
        WHEN $key = 'summer' THEN 'Summer' ELSE (TRIM($alias.semester) COLLATE utf8mb4_unicode_ci) END";
}

final class KnsAcademicTerms
{
    public function __construct(private PDO $pdo) {}

    public function schedule(array $input, int $staffId): array
    {
        $values = [];
        foreach (['academic_year', 'semester', 'start_date', 'official_end_date'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 60) throw new KnsAcademicTermError('Enter valid term details.');
            $values[$key] = trim($value);
        }
        $year = knsAcademicYear($values['academic_year']);
        $semester = knsAcademicSemester($values['semester']);
        $start = knsAcademicTermDate($values['start_date'], 'Start Date');
        $end = knsAcademicTermDate($values['official_end_date'], 'Official End Date');
        if ($end < $start) throw new KnsAcademicTermError('Official End Date must be on or after Start Date.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM academic_term_calendar_lock WHERE id = 1 FOR UPDATE')->fetchColumn();
            $statement = $this->pdo->prepare('SELECT id FROM academic_terms WHERE academic_year = ? AND semester = ? LIMIT 1');
            $statement->execute([$year, $semester]);
            if ($statement->fetchColumn() !== false) throw new KnsAcademicTermError('That academic year and semester are already saved.', 409);
            $statement = $this->pdo->prepare('SELECT id FROM academic_terms WHERE start_date <= ? AND official_end_date >= ? LIMIT 1');
            $statement->execute([$end, $start]);
            if ($statement->fetchColumn() !== false) throw new KnsAcademicTermError('These dates overlap a saved term. The next term must start after the current term\'s official end date.', 409);
            $statement = $this->pdo->prepare('INSERT INTO academic_terms (academic_year, semester, start_date, official_end_date, created_by) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$year, $semester, $start, $end, $staffId]);
            $id = (int) $this->pdo->lastInsertId();
            // Associate preserved snapshots with this calendar; do not change their profile data.
            $statement = $this->pdo->prepare('UPDATE student_enrollments e SET e.term_id = ? WHERE e.term_id IS NULL AND '
                . knsTermYearSql('e') . ' = ? AND ' . knsTermSemesterSql('e') . ' = ?');
            $statement->execute([$id, $year, $semester]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['id' => $id, 'academic_year' => $year, 'semester' => $semester,
            'start_date' => $start, 'official_end_date' => $end];
    }

    public function calendar(): array
    {
        $terms = $this->pdo->query('SELECT t.*, (SELECT COUNT(DISTINCT e.student_id) FROM student_enrollments e WHERE e.term_id = t.id) AS student_count
            FROM academic_terms t ORDER BY t.start_date DESC, t.id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $current = null;
        foreach ($terms as &$term) {
            $term['id'] = (int) $term['id'];
            $term['student_count'] = (int) $term['student_count'];
            $term['status'] = knsAcademicTermStatus($term);
            unset($term['created_by']);
            if ($term['status'] === 'Active') $current = $term;
        }
        unset($term);
        $year = (int) substr(knsAcademicTermToday(), 0, 4);
        $years = [];
        for ($offset = -1; $offset <= 4; $offset++) $years[] = ($year + $offset) . '-' . ($year + $offset + 1);
        $savedYears = $this->pdo->query('SELECT DISTINCT academic_year FROM students WHERE academic_year IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_merge($savedYears, array_column($terms, 'academic_year')) as $saved) {
            try { $years[] = knsAcademicYear($saved); } catch (KnsAcademicTermError $error) {}
        }
        $years = array_values(array_unique($years));
        sort($years);
        return ['current' => $current, 'terms' => $terms, 'academic_years' => $years,
            'semesters' => KNS_TERM_SEMESTERS, 'today' => knsAcademicTermToday(), 'timezone' => knsAcademicTermConfig()['timezone']];
    }

    public function records(int $termId, string $kind, int $page): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM academic_terms WHERE id = ?');
        $statement->execute([$termId]);
        $term = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$term) throw new KnsAcademicTermError('Academic term was not found.', 404);
        if (!in_array($kind, ['enrollments', 'grades', 'assessments'], true)) throw new KnsAcademicTermError('Choose a valid history record type.');
        $studentJoin = ' JOIN students s ON s.student_number COLLATE utf8mb4_unicode_ci = g.student_number COLLATE utf8mb4_unicode_ci';
        if ($kind === 'enrollments') {
            $from = ' FROM student_enrollments e JOIN students s ON s.id = e.student_id WHERE e.term_id = ?';
            $parameters = [$termId];
            $select = "e.id, s.student_number, CONCAT_WS(' ',s.first_name,s.last_name) AS student_name,
                e.course, e.year_level, e.section, e.enrollment_status, e.recorded_at";
            $order = 'e.id DESC';
        } else {
            $table = $kind === 'grades' ? 'subject_grades' : 'grade_assessments';
            $from = ' FROM ' . $table . ' g' . $studentJoin . ' WHERE ' . knsTermYearSql('g') . ' = ? AND ' . knsTermSemesterSql('g') . ' = ?';
            $parameters = [$term['academic_year'], $term['semester']];
            $select = "g.*, CONCAT_WS(' ',s.first_name,s.last_name) AS student_name";
            $order = 'g.id DESC';
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $statement->execute($parameters);
        $total = (int) $statement->fetchColumn();
        $pages = max(1, (int) ceil($total / 20));
        $page = max(1, min($page, $pages));
        $statement = $this->pdo->prepare('SELECT ' . $select . $from . ' ORDER BY ' . $order . ' LIMIT 20 OFFSET ' . (($page - 1) * 20));
        $statement->execute($parameters);
        return ['records' => $statement->fetchAll(PDO::FETCH_ASSOC), 'kind' => $kind, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }
}
