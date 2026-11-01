<?php
// Shared only by request records, request submission, and human-to-human Messenger.
require_once __DIR__ . '/../auth/session.php';

function requestJson(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => $status >= 200 && $status < 300] + $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function requestFail(int $status, string $message): never
{
    requestJson($status, ['message' => $message]);
}

function requestText(array $source, string $key, int $max, bool $required = false): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
        || mb_strlen($value, 'UTF-8') > $max || ($required && trim($value) === '')) {
        throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $key) . '.');
    }
    return trim($value);
}

function requestInteger(mixed $value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,17}$/', (string) $value)) {
        requestFail(400, 'Invalid request or attachment identifier.');
    }
    return (int) $value;
}

function requestToken(array $source, string $key): string
{
    $value = requestText($source, $key, 36, true);
    if (!preg_match('/^[a-f0-9-]{36}$/D', $value)) {
        throw new InvalidArgumentException('Invalid submission token. Reload the request page.');
    }
    return $value;
}

function requestTypes(): array
{
    return ['Module Request', 'Grade Record Request', 'Certificate of Enrollment',
        'Good Moral Certificate', 'Certificate of Candidacy', 'Honorable Dismissal',
        'Student ID Replacement', 'Other Request'];
}

function requestRecord(PDO $pdo, int $id, array $actor, bool $lock = false): array
{
    $sql = 'SELECT r.*, s.student_number, s.first_name, s.middle_name, s.last_name, s.suffix,
        s.course, s.year_level, s.section, s.email
        FROM requests r JOIN students s ON s.id = r.student_id WHERE r.id = ?';
    $params = [$id];
    if ($actor['role'] === 'student') {
        $sql .= ' AND r.student_id = ?';
        $params[] = $actor['id'];
    }
    $stmt = $pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($params);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) requestFail(404, 'Request not found or unavailable to your account.');
    return $record;
}

function requestTimestamp(string $value): string
{
    return str_replace(' ', 'T', $value) . 'Z';
}

function requestPublic(array $row): array
{
    return [
        'id' => (int) $row['id'], 'student_id' => (int) $row['student_id'],
        'reference_no' => $row['reference_no'],
        'student_name' => implode(' ', array_filter([
            $row['first_name'], $row['middle_name'], $row['last_name'], $row['suffix']
        ], fn($part) => $part !== null && $part !== '')),
        'student_number' => $row['student_number'], 'course' => $row['course'],
        'year_level' => (int) $row['year_level'], 'section' => $row['section'],
        'email' => $row['email'], 'request_type' => $row['request_type'],
        'subject' => $row['subject'], 'academic_year' => $row['academic_year'],
        'semester' => $row['semester'], 'purpose' => $row['purpose'],
        'status' => $row['status'], 'created_at' => requestTimestamp($row['created_at'])
    ];
}

function requestPublicAttachment(array $row): array
{
    return ['id' => (int) $row['id'], 'name' => $row['original_name'],
        'size' => (int) $row['byte_size'], 'preview' => $row['preview_mime'] !== null,
        'created_at' => requestTimestamp($row['created_at'])];
}

function requestStorage(): string
{
    // Default for XAMPP: C:/xampp/kns_portal_private/request_attachments.
    // A deployment may set KNS_REQUEST_STORAGE to another absolute private folder.
    $documentRoot = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 3));
    if ($documentRoot === false) throw new RuntimeException('Document root is unavailable.');
    $configured = getenv('KNS_REQUEST_STORAGE');
    $path = $configured ?: dirname($documentRoot) . '/kns_portal_private/request_attachments';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create private request storage.');
    }
    $resolved = realpath($path);
    $root = strtolower(str_replace('\\', '/', rtrim($documentRoot, '/\\')));
    $resolvedNormalized = strtolower(str_replace('\\', '/', $resolved ?: ''));
    if (!$resolved || $resolvedNormalized === $root || str_starts_with($resolvedNormalized, $root . '/')) {
        throw new RuntimeException('Request storage must be outside the web document root.');
    }
    return $resolved;
}

function requestUploadFiles(string $field = 'attachments'): array
{
    $files = $_FILES[$field] ?? null;
    if ($files === null) return [];
    if (!is_array($files) || !isset($files['error']) || !is_array($files['error'])
        || !is_array($files['tmp_name'] ?? null) || !is_array($files['name'] ?? null)) {
        throw new InvalidArgumentException('Invalid attachment upload.');
    }
    if (count($files['error']) > 3) {
        throw new InvalidArgumentException('Attach at most 3 files per message or request.');
    }
    $validated = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $safeImages = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG,
        'image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];
    foreach ($files['error'] as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('An attachment could not be uploaded. Each file must be at most 5 MB.');
        }
        $tmp = $files['tmp_name'][$index] ?? null;
        $name = $files['name'][$index] ?? null;
        if (!is_string($tmp) || !is_string($name) || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Invalid uploaded file.');
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Each attachment must be nonempty and at most 5 MB.');
        }
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', mb_convert_encoding($name, 'UTF-8', 'UTF-8'));
        $name = mb_substr(trim($name), 0, 255) ?: 'attachment';
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        $mime = substr($mime, 0, 100);
        $preview = null;
        if (isset($safeImages[$mime])) {
            $info = @getimagesize($tmp);
            if ($info && $info[2] === $safeImages[$mime] && $info[0] > 0 && $info[1] > 0
                && $info[0] <= 16000 && $info[1] <= 16000 && $info[0] * $info[1] <= 40000000) {
                $preview = $mime;
            }
        }
        $validated[] = ['tmp' => $tmp, 'name' => $name, 'size' => $size,
            'mime' => $mime, 'preview' => $preview];
    }
    return $validated;
}

function requestSaveAttachments(PDO $pdo, int $requestId, ?int $messageId, array $files, array &$moved): void
{
    if (!$files) return;
    $directory = requestStorage();
    $insert = $pdo->prepare('INSERT INTO request_attachments
        (request_id, message_id, original_name, storage_key, mime_type, preview_mime, byte_size)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($files as $file) {
        $key = bin2hex(random_bytes(32));
        $path = $directory . DIRECTORY_SEPARATOR . $key;
        if (!move_uploaded_file($file['tmp'], $path)) {
            throw new RuntimeException('Cannot save the uploaded attachment.');
        }
        $moved[] = $path;
        @chmod($path, 0600);
        $insert->execute([$requestId, $messageId, $file['name'], $key,
            $file['mime'], $file['preview'], $file['size']]);
    }
}

function requestException(Throwable $error, PDO $pdo, array $moved = []): never
{
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($moved as $path) {
        if (is_file($path)) @unlink($path);
    }
    if ($error instanceof InvalidArgumentException) requestFail(422, $error->getMessage());
    error_log('Request Messenger: ' . $error->getMessage());
    if ($error instanceof PDOException && $error->getCode() === '42S22'
        && (str_contains($error->getMessage(), 'event_kind') || str_contains($error->getMessage(), 'event_status'))) {
        requestFail(503, 'Import 20261101_student_notifications.sql into the kns_portal database, then reload the portal.');
    }
    if ($error instanceof PDOException && in_array($error->getCode(), ['42S02', '42S22'], true)) {
        requestFail(503, 'Request storage is not installed. Ask the administrator to run the Request Messenger database migration.');
    }
    requestFail(500, 'Unable to complete the request. Please try again.');
}

$studentId = currentStudentId();
$adminId = $_SESSION['admin_id'] ?? null;
if ($studentId !== null) {
    $requestActor = ['role' => 'student', 'id' => $studentId];
} elseif (is_int($adminId) && $adminId > 0 && $studentId === null) {
    $requestActor = ['role' => 'admin', 'id' => $adminId];
} else {
    requestFail(401, 'Your session expired. Please sign in again.');
}
$requestCsrf = $_SESSION['csrf'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !hash_equals($requestCsrf, $token)) {
        requestFail(403, 'The form expired or exceeded the server upload limit. Reload the request page.');
    }
}
// Polling and file transfers must not hold the PHP session lock.
session_write_close();
require __DIR__ . '/../../db.php';
try {
    $pdo->exec("SET time_zone = '+00:00'");
    $identity = $requestActor['role'] === 'student'
        ? $pdo->prepare("SELECT id FROM students WHERE id = ? AND status = 'Active'")
        : $pdo->prepare('SELECT id FROM admin_account WHERE id = ?');
    $identity->execute([$requestActor['id']]);
    if (!$identity->fetchColumn()) requestFail(401, 'Your account is unavailable. Please sign in again.');
} catch (Throwable $error) {
    requestException($error, $pdo);
}
