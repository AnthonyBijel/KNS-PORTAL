<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
require_once __DIR__ . '/../php/announcement-common.php';

function deletePostReply(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $status < 400, 'message' => $message] + $extra);
    exit;
}
require_once __DIR__ . '/../db.php';
$staff = knsRequireStaffAccess($pdo, 'announcements');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    deletePostReply(405, 'Use the delete confirmation to remove a post.');
}
if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    deletePostReply(403, 'This form expired. Reload the overview and try again.');
}
if (($_POST['confirm_delete'] ?? null) !== '1') deletePostReply(422, 'Confirm that you want to delete this post.');
$id = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) deletePostReply(422, 'Select a valid post to delete.');
require_once __DIR__ . '/../db.php';
try {
    $admin = $pdo->prepare('SELECT id FROM admin_account WHERE id = ?');
    $admin->execute([$staff['id']]);
    if (!$admin->fetchColumn()) deletePostReply(403, 'This administrator account is no longer available.');
    $pdo->beginTransaction();
    $post = $pdo->prepare('SELECT media_path, media_type, media_attachments FROM announcements WHERE id = ? FOR UPDATE');
    $post->execute([$id]);
    $row = $post->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        deletePostReply(404, 'This post was already deleted.');
    }
    $reads = $pdo->prepare("DELETE FROM student_notification_reads WHERE source_type = 'announcement' AND source_id = ?");
    $reads->execute([$id]);
    $delete = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
    $delete->execute([$id]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Announcement deletion failed: ' . $error->getMessage());
    deletePostReply(500, 'Unable to delete the post. Please try again.');
}
// Only remove unshared files inside the announcement upload directory.
// Historical/external paths must never become arbitrary filesystem deletions.
try {
    $references = $pdo->prepare("SELECT 1 FROM announcements WHERE media_path = ? OR JSON_SEARCH(COALESCE(media_attachments, '[]'), 'one', ?, '!', '$[*].path') IS NOT NULL LIMIT 1");
    $directory = realpath(__DIR__ . '/../uploads/announcements');
    foreach (knsAnnouncementAttachments($row) as $attachment) {
        $mediaPath = $attachment['path'];
        if (!preg_match('~\Auploads/announcements/announcement_[a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|gif|webp|mp4|webm)\z~D', $mediaPath)) continue;
        $references->execute([$mediaPath, strtr($mediaPath, ['!' => '!!', '%' => '!%', '_' => '!_'])]);
        if ($references->fetchColumn()) continue;
        $file = realpath(__DIR__ . '/../' . $mediaPath);
        if ($directory !== false && $file !== false && dirname($file) === $directory && is_file($file)) {
            if (!@unlink($file)) error_log('Unable to remove deleted announcement attachment: ' . basename($file));
        }
    }
} catch (Throwable $error) {
    error_log('Deleted announcement attachment cleanup failed: ' . $error->getMessage());
}

deletePostReply(200, 'Post deleted.', ['announcement_id' => $id]);
