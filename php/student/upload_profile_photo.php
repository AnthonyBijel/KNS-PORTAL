<?php
require __DIR__ . '/../auth/session.php';

function photoReply(int $status, string $message, ?string $photoPath = null): never
{
    $ok = $status >= 200 && $status < 300;
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message, 'photo_path' => $photoPath], JSON_UNESCAPED_SLASHES);
    } elseif ($status === 401) {
        header('Location: ../../login.php', true, 303);
    } else {
        $_SESSION['student_photo_feedback'] = ['ok' => $ok, 'message' => $message];
        header('Location: ../../dashboard/dashboard.php?view=student-information', true, 303);
    }
    exit;
}

$studentId = currentStudentId();
if ($studentId === null) {
    photoReply(401, 'Your session expired. Please sign in again.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    photoReply(405, 'Use the photo upload form in Student Information.');
}
$token = $_POST['csrf'] ?? '';
if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
    photoReply(403, 'The form expired or the file exceeded the server limit. Reload Student Information and try again.');
}

require __DIR__ . '/../../db.php';
$newFile = null;
$image = null;
$stream = null;

try {
    $studentQuery = $pdo->prepare('SELECT id FROM students WHERE id = ? AND status = ? LIMIT 1');
    $studentQuery->execute([$studentId, 'Active']);
    if (!$studentQuery->fetchColumn()) {
        photoReply(401, 'Your student account is unavailable. Please sign in again.');
    }
    $file = $_FILES['profile_photo'] ?? null;
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        throw new InvalidArgumentException('Choose a JPG/JPEG, PNG, or WEBP photo (maximum 2 MB).');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The photo exceeds the 2 MB limit or the lower PHP upload limit.',
            UPLOAD_ERR_PARTIAL => 'The photo was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a photo before uploading.',
            default => 'The server could not receive the photo. Please try again later.'
        };
        throw new InvalidArgumentException($message);
    }
    if (!is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null)
        || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Invalid photo upload.');
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size === 0 || $size > 2 * 1024 * 1024) {
        throw new InvalidArgumentException('The photo must be nonempty and at most 2 MB.');
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed[$extension])) {
        throw new InvalidArgumentException('Only JPG/JPEG, PNG, and WEBP photos are accepted.');
    }
    if (!class_exists('finfo') || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('The server needs the PHP Fileinfo and GD extensions to process photos.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $info = @getimagesize($file['tmp_name']);
    if ($mime !== $allowed[$extension] || $info === false || ($info['mime'] ?? '') !== $mime) {
        throw new InvalidArgumentException('The file contents must be a valid image matching its JPG/JPEG, PNG, or WEBP extension.');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 12000000) {
        throw new InvalidArgumentException('Use a photo up to 4096 pixels per side and 12 megapixels in total.');
    }
    $bytes = file_get_contents($file['tmp_name']);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    unset($bytes);
    if ($image === false) {
        throw new InvalidArgumentException('The image data could not be decoded. Export the photo again and retry.');
    }
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }
    if ($extension === 'webp' && !function_exists('imagewebp')) {
        throw new RuntimeException('WEBP support is unavailable on the server. Use JPG or PNG instead.');
    }
    $folder = __DIR__ . '/../../uploads/students';
    if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
        throw new RuntimeException('Unable to create the student photo folder. Contact Student Services.');
    }
    $filename = 'student_' . bin2hex(random_bytes(20)) . '.' . $extension;
    $newFile = $folder . '/' . $filename;
    $stream = @fopen($newFile, 'xb');
    if ($stream === false) {
        // Do not remove a pre-existing file if exclusive creation failed.
        $newFile = null;
        throw new RuntimeException('Unable to save the photo. Contact Student Services.');
    }
    if ($extension !== 'jpg') {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    // Re-encode decoded pixels, discarding original metadata and appended content.
    $saved = match ($extension) {
        'jpg' => @imagejpeg($image, $stream, 90),
        'png' => @imagepng($image, $stream, 6),
        'webp' => @imagewebp($image, $stream, 90)
    };
    fclose($stream);
    $stream = null;
    imagedestroy($image);
    $image = null;
    clearstatcache(true, $newFile);
    if (!$saved || !filesize($newFile)) {
        throw new RuntimeException('Unable to save the photo. Please try again later.');
    }
    $photoPath = 'uploads/students/' . $filename;
    $update = $pdo->prepare('UPDATE students SET profile_photo = ? WHERE id = ? AND status = ?');
    $update->execute([$photoPath, $studentId, 'Active']);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Your student account changed. Reload Student Information and try again.');
    }
    // The old image is retained. Only the DB pointer changes after the new file is saved.
    photoReply(200, 'Your profile photo has been updated.', $photoPath);
} catch (Throwable $error) {
    if (is_resource($stream)) {
        fclose($stream);
    }
    if ($image instanceof GdImage) {
        imagedestroy($image);
    }
    if ($newFile !== null && is_file($newFile)) {
        @unlink($newFile); // Only this request's randomly named new file can be removed.
    }
    if ($error instanceof InvalidArgumentException) {
        photoReply(422, $error->getMessage());
    }
    error_log('Student photo upload failed: ' . $error->getMessage());
    $message = $error instanceof PDOException
        ? 'The database could not save the photo. Your previous photo is unchanged. Please try again later.'
        : ($error instanceof RuntimeException ? $error->getMessage() : 'Unable to process the photo. Please try again later.');
    photoReply(500, $message);
}
