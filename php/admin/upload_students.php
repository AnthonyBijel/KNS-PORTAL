<?php
require __DIR__ . '/../auth/session.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function importEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function failUpload(string $message, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Student import</title>';
    echo '<h1>Student import could not start</h1><p>' . importEscape($message) . '</p>';
    echo '<p>No student records were changed.</p><a href="../../admin/dashboard.php?view=results">Back to Students List</a></html>';
    exit;
}

if (empty($_SESSION['admin_id'])) {
    header('Location: ../../login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    failUpload('Use the Upload Students form on the admin Students List.', 405);
}
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    failUpload('The form expired or the upload exceeded the server limit. Reload Students List and try again.', 403);
}

$file = $_FILES['student_excel'] ?? null;
if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
    failUpload('Select an .xlsx or .xls workbook (maximum 10 MB).');
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    $message = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The workbook exceeds the upload size limit (maximum 10 MB, or the lower PHP limit).',
        UPLOAD_ERR_PARTIAL => 'The workbook was only partially uploaded. Try again.',
        UPLOAD_ERR_NO_FILE => 'Select an .xlsx or .xls workbook.',
        default => 'The server could not receive the workbook. Check the PHP upload temporary folder.'
    };
    failUpload($message);
}
if (!is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)
    || !is_uploaded_file($file['tmp_name'])) {
    failUpload('Invalid uploaded workbook.');
}
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['xlsx', 'xls'], true)) {
    failUpload('Only .xlsx and .xls workbooks are accepted. Upload the Excel file directly.');
}
$size = filesize($file['tmp_name']);
if ($size === false || $size === 0 || $size > 10 * 1024 * 1024) {
    failUpload('The workbook must be nonempty and at most 10 MB.');
}

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../vendor/autoload.php';

// Match the nullable columns and lengths in the local students table.
$required = ['student_number', 'first_name', 'last_name', 'course', 'year_level', 'section'];
$limits = [
    'student_number' => 30, 'first_name' => 100, 'middle_name' => 100,
    'last_name' => 100, 'suffix' => 20, 'course' => 150, 'section' => 50,
    'email' => 254, 'contact_number' => 30, 'guardian_name' => 200,
    'guardian_contact' => 30
];
$fields = array_merge(array_keys($limits), ['password', 'year_level', 'status', 'address', 'birth_date']);
$aliases = [
    'studentno' => 'student_number', 'studentnum' => 'student_number',
    'studentid' => 'student_number', 'studentidnumber' => 'student_number',
    'givenname' => 'first_name', 'givennames' => 'first_name', 'firstname' => 'first_name',
    'surname' => 'last_name', 'familyname' => 'last_name',
    'middlename' => 'middle_name', 'namesuffix' => 'suffix',
    'program' => 'course', 'courseprogram' => 'course', 'year' => 'year_level',
    'set' => 'section', 'studentstatus' => 'status', 'emailaddress' => 'email',
    'phone' => 'contact_number', 'phonenumber' => 'contact_number',
    'mobile' => 'contact_number', 'mobilenumber' => 'contact_number',
    'contactno' => 'contact_number', 'homeaddress' => 'address',
    'birthday' => 'birth_date', 'dateofbirth' => 'birth_date', 'dob' => 'birth_date',
    'guardian' => 'guardian_name', 'guardianphone' => 'guardian_contact',
    'guardiancontactnumber' => 'guardian_contact', 'guardiancontactno' => 'guardian_contact'
];
function normalizeStudentHeader(string $header): string
{
    $header = str_replace("\xEF\xBB\xBF", '', $header);
    return mb_strtolower(preg_replace('/[\s\p{Z}_-]+/u', '', $header) ?? '', 'UTF-8');
}
foreach ($fields as $field) {
    $aliases[normalizeStudentHeader($field)] = $field;
}

function parseStudentBirthDate($value, int $calendar): ?string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return null;
    }
    // Parse strings strictly: invalid days must not roll over into the next month.
    $formats = ['Y-m-d', 'Y/m/d', 'm/d/Y', 'm-d-Y', 'd/m/Y', 'd-m-Y', 'd.m.Y',
        'F j, Y', 'M j, Y', 'F j Y', 'M j Y', 'j F Y', 'j M Y',
        'd-M-Y', 'd-F-Y', 'm/d/y', 'd/m/y', 'm-d-y', 'd-m-y', 'd-M-y',
        'Ymd', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $text, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && (int) $date->format('Y') >= 1000 && (int) $date->format('Y') <= 9999) {
            return $date->format('Y-m-d');
        }
    }
    if (is_numeric($text) && (float) $text >= 1 && (float) $text < 2958466) {
        $date = ExcelDate::excelToDateTimeObject((float) $text, 'UTC', $calendar);
        if ((int) $date->format('Y') <= 9999) {
            return $date->format('Y-m-d');
        }
    }
    throw new InvalidArgumentException('birth_date is invalid. Use an Excel date, YYYY-MM-DD, MM/DD/YYYY, or a date such as 15 June 2005.');
}

try {
    // Restrict content detection to the two actual Excel formats, regardless of filename.
    $readerType = $extension === 'xlsx' ? IOFactory::READER_XLSX : IOFactory::READER_XLS;
    $reader = IOFactory::createReader($readerType);
    if (!$reader->canRead($file['tmp_name'])) {
        failUpload('The workbook contents do not match its .xlsx or .xls extension. Save it again in Excel format.');
    }
    $book = $reader->load($file['tmp_name']);
    $sheet = $book->getSheetByName('Student Upload') ?? $book->getSheet(0);
    $headers = [];
    $headerErrors = [];
    $headerCells = $sheet->getRowIterator(1, 1)->current()->getCellIterator();
    $headerCells->setIterateOnlyExistingCells(true);
    foreach ($headerCells as $cell) {
        $key = normalizeStudentHeader((string) $cell->getValue());
        if (!isset($aliases[$key])) {
            continue; // Includes legacy photo columns: photos never enter the import data.
        }
        $field = $aliases[$key];
        if (isset($headers[$field])) {
            $headerErrors[] = "Duplicate header for {$field} in columns {$headers[$field]} and {$cell->getColumn()}. Keep only one.";
        } else {
            $headers[$field] = $cell->getColumn();
        }
    }
    foreach ($required as $field) {
        if (!isset($headers[$field])) {
            $headerErrors[] = "Cannot identify required header: {$field}.";
        }
    }
    if ($headerErrors) {
        failUpload(implode(' ', $headerErrors) . ' Put headers in row 1; see the downloadable template.');
    }
} catch (Throwable $error) {
    error_log('Student workbook read failed: ' . $error->getMessage());
    failUpload('Unable to read the workbook. Upload an unencrypted, valid .xlsx or .xls file with headers in row 1.');
}

$counts = ['imported' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
$rowErrors = [];
$findStudent = $pdo->prepare('SELECT id FROM students WHERE student_number = ? LIMIT 1 FOR UPDATE');
$lastRow = $sheet->getHighestDataRow();
if ($lastRow >= 2) {
    foreach ($sheet->getRowIterator(2, $lastRow) as $row) {
        $rowNumber = $row->getRowIndex();
        try {
            $blank = true;
            // Unknown extra columns do not turn an otherwise empty student row into an error.
            foreach ($headers as $column) {
                if (trim((string) $sheet->getCell($column . $rowNumber)->getValue()) !== '') {
                    $blank = false;
                    break;
                }
            }
            if ($blank) {
                $counts['skipped']++;
                continue;
            }
            $values = [];
            foreach ($headers as $field => $column) {
                $cell = $sheet->getCell($column . $rowNumber);
                if ($cell->getDataType() === DataType::TYPE_FORMULA || $cell->getDataType() === DataType::TYPE_ERROR) {
                    throw new InvalidArgumentException("{$field} must contain a value, not an Excel formula or error.");
                }
                // Display formats preserve leading zeros on IDs and phone numbers.
                $raw = in_array($field, ['student_number', 'contact_number', 'guardian_contact'], true)
                    ? $cell->getFormattedValue() : $cell->getValue();
                $values[$field] = $field === 'password' ? (string) ($raw ?? '') : trim((string) ($raw ?? ''));
            }
            foreach ($required as $field) {
                if (($values[$field] ?? '') === '') {
                    throw new InvalidArgumentException("Missing required value: {$field}.");
                }
            }
            foreach ($limits as $field => $limit) {
                if (isset($values[$field]) && mb_strlen($values[$field], 'UTF-8') > $limit) {
                    throw new InvalidArgumentException("{$field} exceeds {$limit} characters.");
                }
            }
            if (isset($values['address']) && strlen($values['address']) > 65535) {
                throw new InvalidArgumentException('address exceeds the database TEXT limit.');
            }
            if (!preg_match('/^(?:year\s*)?(\d{1,3})(?:st|nd|rd|th)?(?:\s*year)?$/i', $values['year_level'], $year)
                || (int) $year[1] < 1 || (int) $year[1] > 255) {
                throw new InvalidArgumentException('year_level must be a whole number from 1 to 255 (for example 3 or 3rd Year).');
            }
            $values['year_level'] = (int) $year[1];
            if (($values['email'] ?? '') !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('email is invalid. Leave it blank if unavailable.');
            }
            if (($values['status'] ?? '') !== '') {
                $statuses = ['active' => 'Active', 'inactive' => 'Inactive', 'graduated' => 'Graduated'];
                $statusKey = strtolower($values['status']);
                if (!isset($statuses[$statusKey])) {
                    throw new InvalidArgumentException('status must be Active, Inactive, or Graduated.');
                }
                $values['status'] = $statuses[$statusKey];
            } else {
                // The DB supplies Active on insert; preserve status on update.
                unset($values['status']);
            }
            if (array_key_exists('birth_date', $values)) {
                $values['birth_date'] = parseStudentBirthDate($values['birth_date'], $book->getExcelCalendar());
            }
            $password = $values['password'] ?? '';
            unset($values['password']);
            if (trim($password) !== '') {
                if (strlen($password) > 72) {
                    throw new InvalidArgumentException('password exceeds 72 bytes. Use a shorter password.');
                }
                $values['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            foreach ($values as $field => $value) {
                if ($value === '' && !in_array($field, $required, true)) {
                    $values[$field] = null;
                }
            }

            // A transaction per row lets valid records survive another row's failure.
            // Lock the existing account so blank passwords cannot create passwordless accounts.
            $pdo->beginTransaction();
            $findStudent->execute([$values['student_number']]);
            $studentId = $findStudent->fetchColumn();
            if ($studentId === false && !isset($values['password_hash'])) {
                throw new InvalidArgumentException('password is required for a new student account.');
            }
            $columns = array_keys($values); // All keys come from the fixed header whitelist.
            if ($studentId !== false) {
                unset($values['student_number']);
                $assignments = array_map(static fn ($field) => "`{$field}` = ?", array_keys($values));
                $statement = $pdo->prepare('UPDATE students SET ' . implode(', ', $assignments) . ' WHERE id = ?');
                $statement->execute([...array_values($values), $studentId]);
            } else {
                $quoted = array_map(static fn ($field) => "`{$field}`", $columns);
                $statement = $pdo->prepare('INSERT INTO students (' . implode(', ', $quoted)
                    . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
                $statement->execute(array_values($values));
            }
            // profile_photo is deliberately absent from both INSERT and UPDATE.
            $pdo->commit();
            $counts['imported']++;
            $counts[$studentId === false ? 'created' : 'updated']++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof InvalidArgumentException) {
                $reason = $error->getMessage();
            } elseif ($error instanceof PDOException) {
                $reason = 'Database could not save this row (constraint conflict or database error). Check the students schema and server log, then retry this row.';
                error_log("Student import row {$rowNumber}: " . $error->getMessage());
            } else {
                $reason = 'Unable to read or save this row. Check its cell values and the server log.';
                error_log("Student import row {$rowNumber}: " . $error->getMessage());
            }
            $rowErrors[] = ['row' => $rowNumber, 'reason' => $reason];
        }
    }
}
$book->disconnectWorksheets();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student import results</title>
</head>
<body>
    <h1>Student import results</h1>
    <p>Imported: <strong><?= $counts['imported'] ?></strong>
        (<?= $counts['created'] ?> new, <?= $counts['updated'] ?> updated).</p>
    <p>Skipped blank rows: <strong><?= $counts['skipped'] ?></strong>.</p>
    <p>Rows with errors: <strong><?= count($rowErrors) ?></strong>.</p>
    <?php if ($rowErrors): ?>
        <p>Correct the following rows and upload them again. Successfully imported rows remain saved.</p>
        <table border="1" cellpadding="8">
            <thead><tr><th scope="col">Excel row</th><th scope="col">Reason</th></tr></thead>
            <tbody>
                <?php foreach ($rowErrors as $error): ?>
                    <tr><td><?= $error['row'] ?></td><td><?= importEscape($error['reason']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($counts['imported'] === 0): ?>
        <p>No student records were found below the header row.</p>
    <?php endif; ?>
    <p><a href="../../admin/dashboard.php?view=results">Back to Students List</a></p>
</body>
</html>
