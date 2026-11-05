<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
require_once __DIR__ . '/../db.php';
$studentListStaff = knsRequireStaffAccess($pdo, 'results');
require_once __DIR__ . '/../php/student-term-enrollments.php';
knsSyncTermAccounts($pdo);
$search = $_GET['q'] ?? '';
if (!is_string($search) || !mb_check_encoding($search, 'UTF-8') || mb_strlen($search, 'UTF-8') > 300) {
    http_response_code(422);
    exit('Enter a student name, student number, or section of at most 300 characters.');
}
$search = preg_replace('/\s+/u', ' ', trim($search));

$pageValue = $_GET['page'] ?? '1';
$perPageValue = $_GET['per_page'] ?? '10';
if (!is_string($pageValue) || filter_var($pageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
    || !is_string($perPageValue) || !in_array($perPageValue, ['10', '25', '50', '100'], true)) {
    http_response_code(422);
    exit('Choose a valid page and 10, 25, 50, or 100 students per page.');
}
$page = (int) $pageValue;
$perPage = (int) $perPageValue;

$filters = [];
foreach (['academic_year' => 20, 'semester' => 30, 'course' => 150, 'year_level' => 3, 'section' => 50] as $name => $maxLength) {
    $value = $_GET[$name] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
        http_response_code(422);
        exit('Choose valid student filters.');
    }
    $filters[$name] = trim($value);
}
if ($filters['year_level'] !== '' && filter_var($filters['year_level'], FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 255]]) === false) {
    http_response_code(422);
    exit('Choose a valid year level.');
}

require_once __DIR__ . '/../db.php';

// Dropdown values come from the stored records, including uploaded course/section names.
$filterOptions = [
    'academic_year' => $pdo->query("SELECT DISTINCT academic_year FROM students WHERE TRIM(academic_year) <> '' ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN),
    'semester' => $pdo->query("SELECT DISTINCT semester FROM students WHERE TRIM(semester) <> '' ORDER BY semester")->fetchAll(PDO::FETCH_COLUMN),
    'course' => $pdo->query("SELECT DISTINCT course FROM students WHERE TRIM(course) <> '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN),
    'year_level' => $pdo->query('SELECT DISTINCT year_level FROM students ORDER BY year_level')->fetchAll(PDO::FETCH_COLUMN),
    'section' => $pdo->query("SELECT DISTINCT section FROM students WHERE TRIM(section) <> '' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN),
];
foreach ($filters as $name => $value) {
    if ($value !== '' && !in_array($value, array_map('strval', $filterOptions[$name]), true)) {
        http_response_code(422);
        exit('Choose student filters from the available options.');
    }
}

$studentConditions = [];
$studentParameters = [];
if ($search !== '') {
    // Group search alternatives so every selected filter still applies.
    $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $studentConditions[] = <<<'SQL'
        (s.student_number LIKE ? ESCAPE '!'
           OR s.section LIKE ? ESCAPE '!'
           OR CONCAT_WS(' ', s.first_name, NULLIF(s.middle_name, ''), s.last_name) LIKE ? ESCAPE '!'
           OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ? ESCAPE '!'
           OR CONCAT_WS(' ', s.last_name, s.first_name) LIKE ? ESCAPE '!'
           OR CONCAT_WS(', ', s.last_name, s.first_name) LIKE ? ESCAPE '!')
        SQL;
    $studentParameters = array_fill(0, 6, $pattern);
}
foreach (['academic_year', 'semester', 'course', 'year_level', 'section'] as $name) {
    if ($filters[$name] !== '') {
        $studentConditions[] = 's.' . $name . ' = ?';
        $studentParameters[] = $filters[$name];
    }
}

$studentWhere = $studentConditions ? ' WHERE ' . implode(' AND ', $studentConditions) : '';

$countStatement = $pdo->prepare('SELECT COUNT(*) FROM students s' . $studentWhere);
$countStatement->execute($studentParameters);
$totalStudents = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$studentStatement = $pdo->prepare(
    'SELECT s.id, s.requires_password_change, s.student_number, s.first_name, s.middle_name, s.last_name, s.course, s.year_level, s.section, s.status
     FROM students s' . $studentWhere . ' ORDER BY s.id DESC LIMIT ? OFFSET ?'
);
foreach ($studentParameters as $parameterIndex => $parameter) {
    $studentStatement->bindValue($parameterIndex + 1, $parameter, PDO::PARAM_STR);
}
$studentStatement->bindValue(count($studentParameters) + 1, $perPage, PDO::PARAM_INT);
$studentStatement->bindValue(count($studentParameters) + 2, $offset, PDO::PARAM_INT);
$studentStatement->execute();
$students = $studentStatement->fetchAll(PDO::FETCH_ASSOC);
$firstPage = max(1, min($page - 2, $totalPages - 4));
$lastPage = min($totalPages, $firstPage + 4);
?>

<header class="student-list-header" data-kns-term-banner>
    <div class="student-list-header-content">
        <span class="student-list-header-label">STUDENT MANAGEMENT</span>

        <h1>Students List</h1>

        <p>
            Manage student accounts, information, and grades.
            Use filters or search to quickly find a student.
        </p>
    </div>
    <?php require __DIR__ . '/academic-terms-panel.php'; ?>
</header>

<section class="student-list-upload">

    <!-- Upload Student List -->
    <div class="upload-card student-card">
        <div class="excel-icon">
            <span>X</span>
        </div>

        <div class="upload-info">
            <h3>Upload Student List (Excel)</h3>
            <p>Upload student records from an .xlsx or .xls workbook (maximum 10 MB).</p>

            <a href="../downloads/KNS_Student_Upload_Template.xlsx?v=student-setup-20261105" class="template-link" download>
                <span aria-hidden="true">&#8595;</span> Download Excel Template
            </a>
            <form id="student-upload-form" action="../php/admin/upload_students.php"
                method="post" enctype="multipart/form-data" hidden>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="10485760">
                <input type="file" name="student_excel" accept=".xlsx,.xls"
                    aria-label="Choose a student list workbook" required>
            </form>
        </div>

        <button type="button" class="upload-btn green-btn" form="student-upload-form"
            data-student-upload-picker title="Upload a student list workbook (.xlsx or .xls, maximum 10 MB)">
            <span aria-hidden="true">&#8613;</span>
            Upload Students
        </button>
    </div>


    <!-- Upload Grades -->
    <div class="upload-card grades-card">
        <div class="excel-icon blue-excel">
            <span>X</span>
        </div>

        <div class="upload-info">
            <h3>Upload Grades (Excel)</h3>
            <p>Upload student scores/grades for selected subject,<br>
                year and semester.</p>

            <div>
                <a href="../downloads/KNS_Grade_Upload_Template.xlsx" class="template-link" download>
                    <span aria-hidden="true">&#8595;</span> Download Grade Template
                </a>
            </div>
            <div>
                <a href="../downloads/KNS_Assessment_Scores_Template.xlsx" class="template-link" download>
                    <span aria-hidden="true">&#8595;</span> Download Assessment Template
                </a>
            </div>
            <form id="grade-upload-form" action="../php/admin/upload_grades.php"
                method="post" enctype="multipart/form-data" hidden>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="10485760">
                <input type="file" name="grades_excel" accept=".xlsx"
                    aria-label="Choose a grade or assessment workbook" required>
            </form>
            <p id="grade-upload-message" role="status" aria-live="polite"></p>
        </div>

        <button type="button" class="upload-btn blue-btn" form="grade-upload-form"
            data-grade-upload-picker title="Upload a grade or assessment workbook (.xlsx, maximum 10 MB)">
            <span>↥</span>
            <span class="grade-upload-label" data-grade-upload-label>Upload Grades</span>
        </button>
    </div>

</section>

<details class="student-import-instructions">
    <summary>Student import instructions</summary>
    <p>Students upload their own profile photos in Student Information.</p>
    <p>Use the Student Upload sheet (or the first sheet) with headers in row 1 and one student per row.
        Required columns: student_number, first_name, last_name, course, year_level, section.
        New Active accounts need a valid email or phone to receive a generated temporary password. Workbook passwords are ignored; existing valid passwords are preserved.</p>
    <p>Pending Active accounts can receive a fresh temporary password using the Administrator action under Status. Each send replaces the previous temporary password. Check delivery errors before retrying.</p>
    <p>Other template columns are optional. Missing optional columns keep existing data; blank cells in
        supplied optional columns clear that field. Blank status defaults to Active for a new account
        and keeps the current status for an existing account.</p>
    <p>Optional academic_year (for example 2026-2027) and semester (1st Semester, 2nd Semester, or Summer)
        are saved on the student account and used by the list filters. Re-upload the same student_number
        to update these values. Older workbooks can omit these columns to preserve existing values.</p>
    <p>Headers allow case, space, hyphen, and underscore variations, including Student Number and student_number.
        Extra columns are ignored; duplicate recognized headers must be corrected.
        Use 3 or 3rd Year for year_level. Birth dates accept Excel dates, YYYY-MM-DD,
        MM/DD/YYYY (month/day for ambiguous dates), or 15 June 2005.</p>
    <p>Blank rows are skipped. Valid rows are saved even when other rows have errors; results show counts
        and the Excel row number and reason for each error. Existing student numbers update the same account.</p>
</details>

<section class="student-list-filters">

    <?php foreach ([
        'academic_year' => ['academic-year', 'Academic Year', 'All Academic Years'],
        'semester' => ['semester', 'Semester', 'All Semesters'],
        'course' => ['course', 'Course', 'All Courses'],
        'year_level' => ['year-level', 'Year Level', 'All Year Levels'],
        'section' => ['section', 'Set', 'All Sets'],
    ] as $filterName => [$filterId, $filterLabel, $allLabel]): ?>
        <div class="filter-group">
            <label for="<?= $filterId ?>"><?= $filterLabel ?></label>
            <select id="<?= $filterId ?>" name="<?= $filterName ?>" data-student-filter>
                <option value=""><?= $allLabel ?></option>
                <?php foreach ($filterOptions[$filterName] as $value): ?>
                    <?php
                    $optionLabel = (string) $value;
                    if ($filterName === 'year_level') {
                        $level = (int) $value;
                        $suffix = match (true) {
                            $level % 100 >= 11 && $level % 100 <= 13 => 'th',
                            $level % 10 === 1 => 'st',
                            $level % 10 === 2 => 'nd',
                            $level % 10 === 3 => 'rd',
                            default => 'th',
                        };
                        $optionLabel = $level . $suffix . ' Year';
                    }
                    ?>
                    <option value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>"<?= (string) $value === $filters[$filterName] ? ' selected' : '' ?>><?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>

    <button type="button" class="filter-reset">
        ⭯ <span>Reset</span>
    </button>

    <button type="button" class="filter-apply">
        🝖 <span>Apply Filter</span>
    </button>

</section>

<div class="results-main-table">

    <div class="results-main-table-header">
        <img src="..//asset/logo/people.png" alt="">
        <h1>Students List</h1>

        <div class="studentlist-total">
            <h1>Total Student:</h1>
            <p><?= htmlspecialchars((string) $totalStudents, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="searchbox">
            <img src="..//asset/logo/image.png" alt="">
            <input class="searchbar" type="text" placeholder="Search by name, student, or section..."
                id="student-search" name="q" maxlength="300" autocomplete="off"
                aria-label="Search students by name, student number, or section"
                aria-controls="student-results-body"
                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        </div>

    </div>

    <div class="student-table-scroll">
    <table class="results-table">
        <thead>
            <tr>
                <th> <input type="checkbox"></th>
                <th>#</th>
                <th>Student Number</th>
                <th>Student Name</th>
                <th>course</th>
                <th>Year Level</th>
                <th>Section</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody id="student-results-body" aria-live="polite">
            <?php if (empty($students)): ?>
                <tr>
                    <td colspan="8">No students found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($students as $index => $student): ?>
                    <?php
                    $studentName = trim(
                        $student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']
                    );
                    ?>
                    <tr>
                        <td><input type="checkbox"></td>
                        <td><?= htmlspecialchars((string) ($offset + $index + 1), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($student['student_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($student['course'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($student['year_level'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($student['section'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div class="student-active-list">
                                <span class="status-dot-table"></span>
                                <span><?= htmlspecialchars((string) ($student['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ((int) $student['requires_password_change']): ?>
                                <small>Password setup pending</small>
                                <?php if ($student['status'] === 'Active' && $studentListStaff['role'] === 'Administrator'): ?>
                                    <form method="post" action="../php/admin/resend-student-password.php">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <button type="submit">Send fresh temporary password</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <!-- BOTTOM CONTROLS -->
    <div class="student-table-footer">

        <!-- Show entries -->
        <div class="entries-control">
            <span>Show</span>
            <select id="student-entries" aria-label="Students per page">
                <?php foreach ([10, 25, 50, 100] as $size): ?>
                    <option value="<?= $size ?>"<?= $size === $perPage ? ' selected' : '' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
            <span>entries</span>
        </div>

        <!-- Pagination -->
        <div class="pagination" role="navigation" aria-label="Student list pages"
            data-current-page="<?= $page ?>" data-total-pages="<?= $totalPages ?>">
            <button type="button" class="page-arrow" data-page="<?= max(1, $page - 1) ?>"
                aria-label="Previous page"<?= $page <= 1 ? ' disabled' : '' ?>>&#8249;</button>

            <?php for ($pageNumber = $firstPage; $pageNumber <= $lastPage; $pageNumber++): ?>
                <button type="button" data-page="<?= $pageNumber ?>"
                    <?= $pageNumber === $page ? 'class="current-page" aria-current="page"' : '' ?>><?= $pageNumber ?></button>
            <?php endfor; ?>

            <button type="button" class="page-arrow" data-page="<?= min($totalPages, $page + 1) ?>"
                aria-label="Next page"<?= $page >= $totalPages ? ' disabled' : '' ?>>&#8250;</button>
        </div>

    </div>
</div>
