<?php
require __DIR__ . '/../php/auth/session.php';
require_once __DIR__ . '/../php/announcement-common.php';

function postReply(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $status < 400, 'message' => $message] + $extra);
    } elseif ($status < 400) {
        header('Location: dashboard.php?posted=success', true, 303);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>' . knsAnnouncementEscape($message) . '</p><a href="dashboard.php">Return to your portal</a>';
    }
    exit;
}
if (empty($_SESSION['admin_id'])) postReply(401, 'Your session expired. Please sign in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    postReply(405, 'Use the Create Post form to publish an announcement.');
}
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    postReply(413, 'The upload exceeded the server limit. Choose a smaller attachment and try again.');
}
if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    postReply(403, 'This form expired. Reload the overview and try again.');
}
$token = $_POST['submission_token'] ?? '';
if (!is_string($token) || !array_key_exists($token, $_SESSION['announcement_submissions'] ?? [])) {
    postReply(403, 'This form expired. Reload the overview and try again.');
}
// PHP's session lock serializes requests. A retry with the same token returns the saved post.
if ($_SESSION['announcement_submissions'][$token] !== null) {
    postReply(200, 'Your post was published.', ['announcement_id' => $_SESSION['announcement_submissions'][$token]]);
}
$fields = [];
foreach (['announcement_type', 'title', 'highlights', 'description'] as $field) {
    if (!is_string($_POST[$field] ?? null)) postReply(422, 'Post type, title, Highlights, and description are required.');
    $fields[$field] = trim($_POST[$field]);
    if ($fields[$field] === '') postReply(422, 'Post type, title, Highlights, and description are required.');
}
if ($fields['announcement_type'] === 'Events') $fields['announcement_type'] = 'Event';
if (!in_array($fields['announcement_type'], ['Academic', 'Event', 'Guidelines', 'Other'], true)) postReply(422, 'Select a valid post type.');
foreach (['title' => 200, 'highlights' => 1000, 'description' => 20000] as $field => $limit) {
    if (mb_strlen($fields[$field], 'UTF-8') > $limit) postReply(422, ucfirst($field) . ' must be at most ' . $limit . ' characters.');
}
try {
    $expiration = knsAnnouncementExpiration($_POST['duration'] ?? 'none', $_POST['expires_at'] ?? '');
    $fields['expires_at'] = $expiration?->format('Y-m-d H:i:s');
} catch (InvalidArgumentException $error) {
    postReply(422, $error->getMessage());
}
require __DIR__ . '/../db.php';
$admin = $pdo->prepare('SELECT id FROM admin_account WHERE id = ?');
$admin->execute([(int) $_SESSION['admin_id']]);
if (!$admin->fetchColumn()) postReply(403, 'This administrator account is no longer available.');
$mediaPath = null;
$mediaType = null;
$destination = null;
if (isset($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['media'];
    if (!is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK) postReply(422, 'The attachment could not be uploaded. Check its size and try again.');
    if (($file['size'] ?? 0) < 1 || $file['size'] > 20 * 1024 * 1024) postReply(422, 'Choose a nonempty attachment of at most 20 MB.');
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) postReply(422, 'Select a valid attachment.');
    $mime = mime_content_type($file['tmp_name']);
    $allowedFiles = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];
    if (!isset($allowedFiles[$mime])) postReply(422, 'Choose a JPG, PNG, GIF, WEBP, MP4, or WEBM file.');
    $mediaType = str_starts_with($mime, 'image/') ? 'image' : 'video';
    $uploadFolder = __DIR__ . '/../uploads/announcements/';
    if (!is_dir($uploadFolder) && !mkdir($uploadFolder, 0755, true)) postReply(500, 'Unable to prepare the attachment folder.');
    $fileName = 'announcement_' . bin2hex(random_bytes(16)) . '.' . $allowedFiles[$mime];
    $destination = $uploadFolder . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) postReply(500, 'Unable to save the attachment. Please try again.');
    $mediaPath = 'uploads/announcements/' . $fileName;
}
try {
    $stmt = $pdo->prepare('INSERT INTO announcements (announcement_type, title, highlights, description, media_path, media_type, expires_at) VALUES (:announcement_type, :title, :highlights, :description, :media_path, :media_type, :expires_at)');
    $stmt->execute($fields + ['media_path' => $mediaPath, 'media_type' => $mediaType]);
    $id = (int) $pdo->lastInsertId();
    $_SESSION['announcement_submissions'][$token] = $id;
    postReply(201, 'Your post was published.', ['announcement_id' => $id]);
} catch (Throwable $error) {
    if ($destination !== null && is_file($destination)) unlink($destination);
    error_log('Announcement publishing failed: ' . $error->getMessage());
    postReply(500, 'Unable to publish your post. Please try again.');
}
