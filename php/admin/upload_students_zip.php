<?php
require __DIR__ . '/../auth/session.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ../../login.php');
    exit;
}

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function failUpload(string $message): void
{
    http_response_code(422);
    echo '<h2>Upload failed</h2>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<a href="../../admin/dashboard.php">Back to dashboard</a>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failUpload('Invalid request.');
}

if (
    empty($_FILES['student_zip']['tmp_name']) ||
    $_FILES['student_zip']['error'] !== UPLOAD_ERR_OK
) {
    failUpload('Please select a ZIP file.');
}

$extension = strtolower(
    pathinfo($_FILES['student_zip']['name'], PATHINFO_EXTENSION)
);

if ($extension !== 'zip') {
    failUpload('Only ZIP files are allowed.');
}

if (!class_exists('ZipArchive')) {
    failUpload('PHP ZipArchive extension is not enabled.');
}

$tempFolder = sys_get_temp_dir() . '/kns_students_' . bin2hex(random_bytes(8));
mkdir($tempFolder, 0777, true);

$zip = new ZipArchive();

if ($zip->open($_FILES['student_zip']['tmp_name']) !== true) {
    failUpload('Unable to open ZIP file.');
}

$zip->extractTo($tempFolder);
$zip->close();

$excelFile = $tempFolder . '/students.xlsx';
$photosFolder = $tempFolder . '/photos';

// Windows can hide the extension when a workbook is renamed.
if (!is_file($excelFile) && is_file($excelFile . '.xlsx')) {
    $excelFile .= '.xlsx';
}

if (!is_file($excelFile)) {
    failUpload('The ZIP must contain students.xlsx.');
}

if (!is_dir($photosFolder)) {
    failUpload('The ZIP must contain a photos folder.');
}

try {
    $spreadsheet = IOFactory::load($excelFile);
    $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

    if (count($rows) < 2) {
        failUpload('The Excel file contains no student records.');
    }

    $headers = [];

    foreach ($rows[1] as $column => $header) {
        $key = strtolower(trim((string) $header));
        $key = preg_replace('/\s+/', '_', $key);
        $headers[$key] = $column;
    }

    $requiredHeaders = [
        'student_number',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'course',
        'year_level',
        'section',
        'status',
        'email',
        'contact_number',
        'address',
        'birth_date',
        'guardian_name',
        'guardian_contact',
        'profile_photo'
    ];

    foreach ($requiredHeaders as $header) {
        if (!isset($headers[$header])) {
            failUpload("Missing Excel column: {$header}");
        }
    }

    $getValue = function (array $row, string $field) use ($headers): string {
        return trim((string) ($row[$headers[$field]] ?? ''));
    };

    $students = [];
    $photoFiles = [];

    foreach ($rows as $rowNumber => $row) {
        if ($rowNumber === 1) {
            continue;
        }

        $studentNumber = $getValue($row, 'student_number');

        if ($studentNumber === '') {
            continue;
        }

        $requiredValues = [
            'password',
            'first_name',
            'last_name',
            'course',
            'year_level',
            'section',
            'status',
            'email',
            'contact_number',
            'address',
            'birth_date',
            'guardian_name',
            'guardian_contact',
            'profile_photo'
        ];

        foreach ($requiredValues as $field) {
            if ($getValue($row, $field) === '') {
                failUpload("Missing {$field} on Excel row {$rowNumber}.");
            }
        }

        $email = $getValue($row, 'email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            failUpload("Invalid email on Excel row {$rowNumber}.");
        }

        $birthDateValue = $row[$headers['birth_date']] ?? '';

        if (is_numeric($birthDateValue)) {
            $birthDate = ExcelDate::excelToDateTimeObject($birthDateValue)
                ->format('Y-m-d');
        } else {
            $birthDateObject = new DateTime((string) $birthDateValue);
            $birthDate = $birthDateObject->format('Y-m-d');
        }

        $photoName = basename($getValue($row, 'profile_photo'));
        $photoSource = $photosFolder . '/' . $photoName;

        // A hidden extension can also leave names such as student.jpg.png.
        if (!is_file($photoSource)) {
            $photoMatches = [];

            foreach (['jpg', 'jpeg', 'png', 'webp'] as $extraExtension) {
                $candidate = $photoSource . '.' . $extraExtension;

                if (is_file($candidate)) {
                    $photoMatches[] = $candidate;
                }
            }

            if (count($photoMatches) > 1) {
                failUpload(
                    "Multiple photos match {$photoName} on Excel row {$rowNumber}. "
                    . 'Use the exact filename in profile_photo.'
                );
            }

            if (count($photoMatches) === 1) {
                $photoSource = $photoMatches[0];
                $photoName = basename($photoSource);
            }
        }

        if (!is_file($photoSource)) {
            failUpload("Profile photo not found: {$photoName}");
        }

        if (getimagesize($photoSource) === false) {
            failUpload("Invalid image file: {$photoName}");
        }

        $photoExtension = strtolower(
            pathinfo($photoName, PATHINFO_EXTENSION)
        );

        if (!in_array($photoExtension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            failUpload("Unsupported photo type: {$photoName}");
        }

        $safeNumber = preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $studentNumber
        );

        $finalPhotoName = $safeNumber . '.' . $photoExtension;
        $finalPhotoPath = __DIR__ . '/../../uploads/students/' . $finalPhotoName;
        $databasePhotoPath = 'uploads/students/' . $finalPhotoName;

        $photoFiles[] = [
            'source' => $photoSource,
            'destination' => $finalPhotoPath
        ];

        $students[] = [
            $studentNumber,
            password_hash($getValue($row, 'password'), PASSWORD_DEFAULT),
            $getValue($row, 'first_name'),
            $getValue($row, 'middle_name'),
            $getValue($row, 'last_name'),
            $getValue($row, 'suffix'),
            $getValue($row, 'course'),
            $getValue($row, 'year_level'),
            $getValue($row, 'section'),
            $getValue($row, 'status'),
            $email,
            $getValue($row, 'contact_number'),
            $getValue($row, 'address'),
            $birthDate,
            $getValue($row, 'guardian_name'),
            $getValue($row, 'guardian_contact'),
            $databasePhotoPath
        ];
    }

    if (empty($students)) {
        failUpload('No valid student records found.');
    }

    $pdo->beginTransaction();

    $sql = "
        INSERT INTO students (
            student_number,
            password_hash,
            first_name,
            middle_name,
            last_name,
            suffix,
            course,
            year_level,
            section,
            status,
            email,
            contact_number,
            address,
            birth_date,
            guardian_name,
            guardian_contact,
            profile_photo
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            password_hash = VALUES(password_hash),
            first_name = VALUES(first_name),
            middle_name = VALUES(middle_name),
            last_name = VALUES(last_name),
            suffix = VALUES(suffix),
            course = VALUES(course),
            year_level = VALUES(year_level),
            section = VALUES(section),
            status = VALUES(status),
            email = VALUES(email),
            contact_number = VALUES(contact_number),
            address = VALUES(address),
            birth_date = VALUES(birth_date),
            guardian_name = VALUES(guardian_name),
            guardian_contact = VALUES(guardian_contact),
            profile_photo = VALUES(profile_photo)
    ";

    $statement = $pdo->prepare($sql);

    foreach ($students as $index => $student) {
        $photo = $photoFiles[$index];

        if (!copy($photo['source'], $photo['destination'])) {
            throw new RuntimeException(
                'Unable to save profile photo.'
            );
        }

        $statement->execute($student);
    }

    $pdo->commit();

    echo '<h2>Upload successful!</h2>';
    echo '<p>' . count($students) . ' student(s) imported.</p>';
    echo '<a href="../../admin/dashboard.php">Back to dashboard</a>';

} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    failUpload($error->getMessage());
}