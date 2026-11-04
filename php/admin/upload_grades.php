<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/../auth/staff-access.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const GRADE_UPLOAD_COLUMNS = [
    'Subject Grades' => [
        'student_number', 'academic_year', 'semester', 'subject_code', 'subject_name',
        'prelim_grade', 'midterm_grade', 'final_grade', 'overall_grade'
    ],
    'Assessments' => [
        'student_number', 'academic_year', 'semester', 'grading_period', 'subject_code',
        'subject_name', 'assessment_type', 'assessment_name', 'score', 'max_score',
        'date_posted', 'remarks'
    ]
];

final class GradeUploadValidationException extends RuntimeException {}

function gradeUploadEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gradeUploadPage(array $errors = [], ?array $counts = null, int $status = 200): never
{
    http_response_code($status);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        $ok = $counts !== null && !$errors;
        $message = $errors ? implode(' ', $errors) . ' No grade records were changed.'
            : ($counts !== null ? sprintf(
                'Upload successful: %d subject grade row(s), %d assessment row(s) saved; %d blank row(s) skipped.',
                $counts['Subject Grades'], $counts['Assessments'], $counts['skipped']
            ) : 'Choose a grade or assessment workbook.');
        echo json_encode(['ok' => $ok, 'message' => $message, 'errors' => $errors, 'counts' => $counts],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Upload Grades | KNS Portal</title>
        <style>
            body { max-width: 900px; margin: 32px auto; padding: 0 20px; font: 16px/1.5 Arial, sans-serif; color: #15382b; }
            form { padding: 20px; border: 1px solid #c9ecdf; border-radius: 8px; background: #f5fcf8; }
            label { display: block; font-weight: bold; margin-bottom: 8px; }
            input[type="file"] { max-width: 100%; margin: 0 12px 12px 0; }
            button { padding: 10px 18px; border: 0; border-radius: 6px; background: #17623f; color: #fff; cursor: pointer; }
            .grade-upload-errors { color: #9d2019; }
            code { overflow-wrap: anywhere; }
        </style>
    </head>
    <body>
        <h1><?= $counts !== null ? 'Grade upload successful' : 'Upload Grades' ?></h1>
        <?php if ($errors): ?>
            <div class="grade-upload-errors" role="alert">
                <h2>Grade upload failed</h2>
                <ul><?php foreach ($errors as $message): ?><li><?= gradeUploadEscape($message) ?></li><?php endforeach; ?></ul>
                <p>No grade records were changed. Correct the workbook and upload it again.</p>
            </div>
        <?php endif; ?>
        <?php if ($counts !== null): ?>
            <div role="status">
                <p>Subject grade rows saved: <strong><?= $counts['Subject Grades'] ?></strong>.</p>
                <p>Assessment rows saved: <strong><?= $counts['Assessments'] ?></strong>.</p>
                <p>Blank rows skipped: <strong><?= $counts['skipped'] ?></strong>.</p>
                <p>All included grade and assessment sheets were saved together. Existing matching records were updated.</p>
            </div>
        <?php endif; ?>
        <form action="upload_grades.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= gradeUploadEscape($_SESSION['csrf']) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="10485760">
            <label for="grades-excel">Grade workbook (.xlsx only, maximum 10 MB)</label>
            <input id="grades-excel" type="file" name="grades_excel" accept=".xlsx" required>
            <button type="submit">Upload Grades</button>
        </form>
        <p>Choose the grade template or the separate assessment template. Supported worksheets are
            Subject Grades, Assessments, and Assessment Scores (the separate assessment template).
            Use headers in row 1 and one record per row. All included sheets are saved in one transaction.
            Student numbers must already exist. Blank rows are skipped; any row error rolls back the entire upload.</p>
        <?php foreach (GRADE_UPLOAD_COLUMNS as $sheetName => $columns): ?>
            <h2><?= gradeUploadEscape($sheetName) ?></h2>
            <p><code><?= gradeUploadEscape(implode(', ', $columns)) ?></code></p>
        <?php endforeach; ?>
        <p>All listed headers are required in each included sheet. Grade values, date_posted, and remarks may be blank.
            Assessment types: activity, quiz, exam, project. Use Excel dates or YYYY-MM-DD for date_posted.
            Supply plain values or formulas with saved results. Keep student numbers as text to preserve leading zeros.</p>
        <p><a href="../../admin/dashboard.php?view=results">Back to Students List</a></p>
    </body>
    </html>
    <?php
    exit;
}

$accessPdo = gradeUploadDatabase();
knsRequireStaffAccess($accessPdo, 'results', '../../login.php');
unset($accessPdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    gradeUploadPage();
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    gradeUploadPage(['Use the grade workbook upload form.'], null, 405);
}
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    gradeUploadPage(['The form expired or the upload exceeded the server limit. Reload this page and try again.'], null, 403);
}
$file = $_FILES['grades_excel'] ?? null;
if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
    gradeUploadPage(['Select an .xlsx grade workbook.'], null, 422);
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    $message = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The workbook exceeds the 10 MB limit or the lower PHP upload limit.',
        UPLOAD_ERR_PARTIAL => 'The workbook was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Select an .xlsx grade workbook.',
        default => 'The server could not receive the workbook. Please try again later.'
    };
    gradeUploadPage([$message], null, 422);
}
if (!is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)
    || !is_uploaded_file($file['tmp_name'])
    || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    gradeUploadPage(['Only directly uploaded .xlsx workbooks are accepted.'], null, 422);
}
$size = filesize($file['tmp_name']);
if ($size === false || $size === 0 || $size > 10 * 1024 * 1024) {
    gradeUploadPage(['The workbook must be nonempty and at most 10 MB.'], null, 422);
}

function gradeUploadDatabase(): PDO
{
    // db.php terminates with its PDO error on connection failure. Filter that output
    // so database details never reach the response; reuse the existing connection.
    ob_start(static function (string $output, int $phase): string {
        if ($phase & PHP_OUTPUT_HANDLER_CLEAN) {
            return '';
        }
        http_response_code(500);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            return json_encode(['ok' => false,
                'message' => 'The database connection is unavailable. No grade records were changed.']);
        }
        header('Content-Type: text/html; charset=utf-8');
        return '<h1>Grade upload failed</h1><p>The database connection is unavailable. No grade records were changed.</p>'
            . '<p><a href="upload_grades.php">Back to grade upload</a></p>';
    });
    try {
        require __DIR__ . '/../../db.php';
    } finally {
        ob_end_clean();
    }
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }
    return $pdo;
}

function gradeUploadHeaders(Worksheet $sheet, array $required): array
{
    $normalize = static fn ($text) => mb_strtolower(
        preg_replace('/[\s\p{Z}_-]+/u', '', str_replace("\xEF\xBB\xBF", '', (string) $text)) ?? '',
        'UTF-8'
    );
    $known = [];
    foreach ($required as $field) {
        $known[$normalize($field)] = $field;
    }
    $headers = [];
    $cells = $sheet->getRowIterator(1, 1)->current()->getCellIterator();
    $cells->setIterateOnlyExistingCells(true);
    foreach ($cells as $cell) {
        $key = $normalize($cell->getValue());
        if (!isset($known[$key])) {
            continue;
        }
        $field = $known[$key];
        if (isset($headers[$field])) {
            throw new GradeUploadValidationException("Duplicate header {$field} in columns {$headers[$field]} and {$cell->getColumn()}.");
        }
        $headers[$field] = $cell->getColumn();
    }
    $missing = array_diff($required, array_keys($headers));
    if ($missing) {
        throw new GradeUploadValidationException('Missing required headers in row 1: ' . implode(', ', $missing) . '.');
    }
    return $headers;
}

function gradeUploadRow(Worksheet $sheet, array $headers, int $rowNumber): ?array
{
    $values = [];
    $blank = true;
    $errors = DataType::getErrorCodes();
    foreach ($headers as $field => $column) {
        $cell = $sheet->getCell($column . $rowNumber);
        $value = $cell->getValue();
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            // Read saved results without evaluating uploaded formulas or external links.
            $value = $cell->getOldCalculatedValue();
            if ($value === null) {
                throw new GradeUploadValidationException("{$field} has a formula without a saved result. Save the workbook in Excel or paste values before uploading.");
            }
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($cell->getDataType() === DataType::TYPE_ERROR || (is_string($value) && isset($errors[$value]))) {
            throw new GradeUploadValidationException("{$field} contains an Excel error. Correct the cell before uploading.");
        }
        if (is_bool($value) || (is_array($value)) || (is_object($value))) {
            throw new GradeUploadValidationException("{$field} must contain a text or numeric value.");
        }
        if ($field === 'student_number' && (is_int($value) || is_float($value))) {
            $value = NumberFormat::toFormattedString($value, $cell->getStyle()->getNumberFormat()->getFormatCode());
        }
        $values[$field] = trim((string) ($value ?? ''));
        if ($values[$field] !== '') {
            $blank = false;
        }
    }
    return $blank ? null : $values;
}

function gradeUploadDecimal(string $value, string $field, int $precision, bool $nullable = false): ?string
{
    if ($value === '' && $nullable) {
        return null;
    }
    if ($value === '' || !is_numeric($value) || !is_finite((float) $value)) {
        throw new GradeUploadValidationException("{$field} must be numeric.");
    }
    $number = round((float) $value, 2);
    if (abs($number) > (10 ** ($precision - 2)) - 0.01) {
        throw new GradeUploadValidationException("{$field} exceeds the database DECIMAL({$precision},2) range.");
    }
    return number_format($number, 2, '.', '');
}

function gradeUploadDate(string $value, int $calendar): ?string
{
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'd/m/Y', 'm-d-Y', 'd-m-Y', 'd.m.Y',
        'F j, Y', 'M j, Y', 'j F Y', 'j M Y', 'd-M-Y', 'Ymd', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
            && (int) $date->format('Y') >= 1000 && (int) $date->format('Y') <= 9999) {
            return $date->format('Y-m-d');
        }
    }
    $minimum = $calendar === ExcelDate::CALENDAR_MAC_1904 ? 0 : 1;
    if (is_numeric($value) && (float) $value >= $minimum && (float) $value < 2958466) {
        $date = ExcelDate::excelToDateTimeObject((float) $value, 'UTC', $calendar);
        if ((int) $date->format('Y') <= 9999) {
            return $date->format('Y-m-d');
        }
    }
    throw new GradeUploadValidationException('date_posted is invalid. Use an Excel date or YYYY-MM-DD.');
}

function gradeUploadValidate(array $values, string $sheetName, int $calendar): array
{
    $nullable = ['prelim_grade', 'midterm_grade', 'final_grade', 'overall_grade', 'date_posted', 'remarks'];
    $limits = ['student_number' => 30, 'academic_year' => 20, 'semester' => 30,
        'subject_code' => 30, 'subject_name' => 150, 'grading_period' => 30,
        'assessment_type' => 30, 'assessment_name' => 150, 'remarks' => 255];
    foreach ($values as $field => $value) {
        if ($value === '' && !in_array($field, $nullable, true)) {
            throw new GradeUploadValidationException("{$field} is required.");
        }
        if (isset($limits[$field]) && mb_strlen($value, 'UTF-8') > $limits[$field]) {
            throw new GradeUploadValidationException("{$field} exceeds {$limits[$field]} characters.");
        }
    }
    if ($sheetName === 'Subject Grades') {
        foreach (['prelim_grade', 'midterm_grade', 'final_grade', 'overall_grade'] as $field) {
            $values[$field] = gradeUploadDecimal($values[$field], $field, 5, true);
        }
    } else {
        $values['assessment_type'] = strtolower($values['assessment_type']);
        if (!in_array($values['assessment_type'], ['activity', 'quiz', 'exam', 'project'], true)) {
            throw new GradeUploadValidationException('assessment_type must be activity, quiz, exam, or project.');
        }
        $values['score'] = gradeUploadDecimal($values['score'], 'score', 10);
        $values['max_score'] = gradeUploadDecimal($values['max_score'], 'max_score', 10);
        $values['date_posted'] = gradeUploadDate($values['date_posted'], $calendar);
        $values['remarks'] = $values['remarks'] === '' ? null : $values['remarks'];
    }
    return $values;
}

$pdo = null;
$book = null;
$location = '';
$stage = 'setup';
try {
    require __DIR__ . '/../../vendor/autoload.php';
    $stage = 'workbook';
    $reader = IOFactory::createReader(IOFactory::READER_XLSX);
    if (!$reader->canRead($file['tmp_name'])) {
        throw new GradeUploadValidationException('The file contents are not a valid .xlsx workbook.');
    }
    $book = $reader->load($file['tmp_name']);
    $sheets = [];
    foreach (GRADE_UPLOAD_COLUMNS as $name => $columns) {
        $sheet = $book->getSheetByName($name);
        if ($name === 'Assessments') {
            $separateSheet = $book->getSheetByName('Assessment Scores');
            if ($sheet !== null && $separateSheet !== null) {
                throw new GradeUploadValidationException('Keep only one assessment worksheet: Assessments or Assessment Scores.');
            }
            $sheet ??= $separateSheet;
        }
        if ($sheet === null) {
            continue;
        }
        $location = $sheet->getTitle() . ', row 1';
        $sheets[$name] = ['sheet' => $sheet, 'headers' => gradeUploadHeaders($sheet, $columns)];
    }
    if (!$sheets) {
        throw new GradeUploadValidationException('No supported worksheet was found. Use Subject Grades, Assessments, or Assessment Scores from the provided templates.');
    }
    $location = '';
    $stage = 'database';
    $pdo = gradeUploadDatabase();
    knsRequireStaffAccess($pdo, 'results', '../../login.php');
    $pdo->beginTransaction();
    $studentQuery = $pdo->prepare('SELECT student_number FROM students WHERE student_number = ? LIMIT 1 LOCK IN SHARE MODE');
    $statements = [
        'Subject Grades' => $pdo->prepare(
            'INSERT INTO subject_grades (student_number, academic_year, semester, subject_code, subject_name,
                prelim_grade, midterm_grade, final_grade, overall_grade)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), prelim_grade = VALUES(prelim_grade),
                midterm_grade = VALUES(midterm_grade), final_grade = VALUES(final_grade), overall_grade = VALUES(overall_grade)'
        ),
        'Assessments' => $pdo->prepare(
            'INSERT INTO grade_assessments (student_number, academic_year, semester, grading_period, subject_code,
                subject_name, assessment_type, assessment_name, score, max_score, date_posted, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject_name = VALUES(subject_name), score = VALUES(score),
                max_score = VALUES(max_score), date_posted = VALUES(date_posted), remarks = VALUES(remarks)'
        )
    ];
    $students = [];
    $counts = ['Subject Grades' => 0, 'Assessments' => 0, 'skipped' => 0];
    foreach ($sheets as $name => $config) {
        $sheet = $config['sheet'];
        $lastRow = $sheet->getHighestDataRow();
        if ($lastRow < 2) {
            continue;
        }
        foreach ($sheet->getRowIterator(2, $lastRow) as $row) {
            $rowNumber = $row->getRowIndex();
            $location = $sheet->getTitle() . ', Excel row ' . $rowNumber;
            $values = gradeUploadRow($sheet, $config['headers'], $rowNumber);
            if ($values === null) {
                $counts['skipped']++;
                continue;
            }
            $values = gradeUploadValidate($values, $name, $book->getExcelCalendar());
            $number = $values['student_number'];
            if (!array_key_exists($number, $students)) {
                // Hold a shared lock until commit so the validated account cannot be deleted mid-import.
                $studentQuery->execute([$number]);
                $existingNumber = $studentQuery->fetchColumn();
                if ($existingNumber === false) {
                    throw new GradeUploadValidationException('student_number does not exist in students. Import the student account first.');
                }
                $students[$number] = $existingNumber;
            }
            $values['student_number'] = $students[$number];
            $parameters = array_map(static fn ($field) => $values[$field], GRADE_UPLOAD_COLUMNS[$name]);
            $statements[$name]->execute($parameters);
            $counts[$name]++;
        }
    }
    $location = '';
    if ($counts['Subject Grades'] + $counts['Assessments'] === 0) {
        throw new GradeUploadValidationException('The workbook contains no grade or assessment data rows. Add at least one record.');
    }
    $pdo->commit();
    gradeUploadPage([], $counts);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error instanceof GradeUploadValidationException) {
        $message = $error->getMessage();
        $status = 422;
    } elseif ($stage === 'workbook') {
        $message = 'Unable to read the workbook. Use a valid, unencrypted .xlsx file based on the provided grade or assessment template.';
        $status = 422;
    } else {
        $message = 'The server could not save the grades. Check the database setup and server log, then try again.';
        $status = 500;
    }
    if (!$error instanceof GradeUploadValidationException) {
        // Log only the exception category and SQLSTATE, never workbook contents or SQL parameters.
        error_log('Grade upload failed: ' . get_class($error)
            . ($error instanceof PDOException ? ' (SQLSTATE ' . $error->getCode() . ')' : ''));
    }
    gradeUploadPage([($location !== '' ? $location . ': ' : '') . $message], null, $status);
}
