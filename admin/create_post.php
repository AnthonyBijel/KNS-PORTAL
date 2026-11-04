<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
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
require_once __DIR__ . '/../db.php';
$staff = knsRequireStaffAccess($pdo, 'announcements');
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
require_once __DIR__ . '/../db.php';
$admin = $pdo->prepare('SELECT id FROM admin_account WHERE id = ?');
$admin->execute([$staff['id']]);
if (!$admin->fetchColumn()) postReply(403, 'This administrator account is no longer available.');
$allowedFiles = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];
try {
    $files = knsAnnouncementUploads($_FILES['media'] ?? null);
    // Validate the entire selection before moving any files.
    foreach ($files as &$file) {
        if (!is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Select valid attachments.');
        $mime = mime_content_type($file['tmp_name']);
        if (!isset($allowedFiles[$mime])) throw new InvalidArgumentException('Choose JPG, PNG, GIF, WEBP images, or one MP4 or WEBM video.');
        $file['type'] = str_starts_with($mime, 'image/') ? 'image' : 'video';
        $file['extension'] = $allowedFiles[$mime];
        if (count($files) > 1 && $file['type'] === 'video') {
            throw new InvalidArgumentException('Choose multiple images or one video. Videos cannot be combined with other attachments.');
        }
    }
    unset($file);
} catch (InvalidArgumentException $error) {
    postReply(422, $error->getMessage());
}
$destinations = [];
$attachments = [];
try {
    $uploadFolder = __DIR__ . '/../uploads/announcements/';
    if ($files && !is_dir($uploadFolder) && !mkdir($uploadFolder, 0755, true)) throw new RuntimeException('Unable to prepare the attachment folder.');
    foreach ($files as $file) {
        $fileName = 'announcement_' . bin2hex(random_bytes(16)) . '.' . $file['extension'];
        $destination = $uploadFolder . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('Unable to save an attachment.');
        $destinations[] = $destination;
        $attachments[] = ['path' => 'uploads/announcements/' . $fileName, 'type' => $file['type']];
    }
    // Keep the first attachment in the legacy columns for existing integrations.
    $fields['media_path'] = $attachments[0]['path'] ?? null;
    $fields['media_type'] = $attachments[0]['type'] ?? null;
    $fields['media_attachments'] = $attachments ? json_encode($attachments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null;
    $stmt = $pdo->prepare('INSERT INTO announcements (announcement_type, title, highlights, description, media_path, media_type, media_attachments, expires_at) VALUES (:announcement_type, :title, :highlights, :description, :media_path, :media_type, :media_attachments, :expires_at)');
    $stmt->execute($fields);
    $id = (int) $pdo->lastInsertId();
    $_SESSION['announcement_submissions'][$token] = $id;
    postReply(201, 'Your post was published.', ['announcement_id' => $id]);
} catch (Throwable $error) {
    foreach ($destinations as $destination) {
        if (is_file($destination) && !@unlink($destination)) error_log('Unable to clean up failed announcement attachment: ' . basename($destination));
    }
    error_log('Announcement publishing failed: ' . $error->getMessage());
    postReply(500, 'Unable to publish your post. Please try again.');
}
