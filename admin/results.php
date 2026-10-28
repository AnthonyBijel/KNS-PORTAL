<?php
require __DIR__ . '/../php/auth/session.php';
if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../db.php';

$studentStatement = $pdo->query(
    'SELECT student_number, first_name, middle_name, last_name, course, year_level, section, status
     FROM students
     ORDER BY id DESC'
);
$students = $studentStatement->fetchAll(PDO::FETCH_ASSOC);
$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
?>

<header class="student-list-header">
    <div class="student-list-header-content">
        <span class="student-list-header-label">STUDENT MANAGEMENT</span>

        <h1>Students List</h1>

        <p>
            Manage student accounts, information, and grades.
            Use filters or search to quickly find a student.
        </p>
    </div>
</header>

<div class="student-list-upload">

</div>

<section class="student-list-upload">

    <!-- Upload Student List -->
    <div class="upload-card student-card">
        <div class="excel-icon">
            <span>X</span>
        </div>

        <div class="upload-info">
            <h3>Upload Student List (Excel)</h3>
            <p>Upload an .xlsx or .xls workbook directly (maximum 10 MB). Students upload their own profile photos in Student Information.</p>

            <a href="../downloads/KNS_Student_Upload_Template.xlsx" class="template-link" download>
                <span>↓</span> Download Excel Template
            </a>
        </div>

        <form
            action="../php/admin/upload_students.php"
            method="POST"
            enctype="multipart/form-data"
            class="student-upload-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="10485760">
            <input
                type="file"
                name="student_excel"
                accept=".xlsx,.xls"
                required>

            <button
                type="submit"
                class="upload-btn green-btn">
                <span>↑</span>
                Upload Students
            </button>
        </form>
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
    <p>Use the Student Upload sheet (or the first sheet) with headers in row 1 and one student per row.
        Required columns: student_number, first_name, last_name, course, year_level, section.
        Supply password for new accounts; leave it blank to keep an existing password.</p>
    <p>Other template columns are optional. Missing optional columns keep existing data; blank cells in
        supplied optional columns clear that field. Blank status defaults to Active for a new account
        and keeps the current status for an existing account.</p>
    <p>Headers allow case, space, hyphen, and underscore variations, including Student Number and student_number.
        Extra columns are ignored; duplicate recognized headers must be corrected.
        Use 3 or 3rd Year for year_level. Birth dates accept Excel dates, YYYY-MM-DD,
        MM/DD/YYYY (month/day for ambiguous dates), or 15 June 2005.</p>
    <p>Blank rows are skipped. Valid rows are saved even when other rows have errors; results show counts
        and the Excel row number and reason for each error. Existing student numbers update the same account.</p>
</details>

<section class="student-list-filters">

    <div class="filter-group">
        <label for="academic-year">Academic Year</label>
        <select id="academic-year">
            <option>2026 - 2027</option>
            <option>2025 - 2026</option>
            <option>2024 - 2025</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="semester">Semester</label>
        <select id="semester">
            <option>1st Semester</option>
            <option>2nd Semester</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="course">Course</label>
        <select id="course">
            <option>All Courses</option>
            <option>BS Business Administration</option>
            <option>BS Computer Science</option>
            <option>BS Accountancy</option>
            <option>BS Elementary Education</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="year-level">Year Level</label>
        <select id="year-level">
            <option>All Year Levels</option>
            <option>1st Year</option>
            <option>2nd Year</option>
            <option>3rd Year</option>
            <option>4th Year</option>
        </select>
    </div>

    <div class="filter-group">
        <label for="section">Set</label>
        <select id="section">
            <option>All Sets</option>
            <option>Set A</option>
            <option>Set B</option>
            <option>Set C</option>
            <option>Set D</option>
            <option>Set E</option>
            <option>Set F</option>
            <option>Set G</option>
            <option>Set H</option>
            <option>Set I</option>
            <option>Set J</option>
            <option>Set K</option>
            <option>Set L</option>
            <option>Set M</option>
            <option>Set N</option>
        </select>
    </div>

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
            <input class="searchbar" type="text" placeholder="Search by name, student, or section...">
        </div>

    </div>

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
        <tbody>
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
                        <td><?= htmlspecialchars((string) ($index + 1), ENT_QUOTES, 'UTF-8') ?></td>
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- BOTTOM CONTROLS -->
    <div class="student-table-footer">

        <!-- Show entries -->
        <div class="entries-control">

            <span>Show</span>

            <select>
                <option>10</option>
                <option>25</option>
                <option>50</option>
                <option>100</option>
            </select>

            <span>entries</span>

        </div>


        <!-- Pagination -->
        <div class="pagination">

            <button class="page-arrow">‹</button>

            <button class="current-page">1</button>

            <button>2</button>
            <button>3</button>
            <button>4</button>
            <button>5</button>

            <button class="page-arrow">›</button>

        </div>

    </div>