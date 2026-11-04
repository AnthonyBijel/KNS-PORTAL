<?php
require __DIR__ . '/../php/auth/session.php';

function deletePostReply(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $status < 400, 'message' => $message] + $extra);
    exit;
}
if (empty($_SESSION['admin_id'])) deletePostReply(401, 'Your session expired. Please sign in again.');
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
require __DIR__ . '/../db.php';
try {
    $admin = $pdo->prepare('SELECT id FROM admin_account WHERE id = ?');
    $admin->execute([(int) $_SESSION['admin_id']]);
    if (!$admin->fetchColumn()) deletePostReply(403, 'This administrator account is no longer available.');
    $pdo->beginTransaction();
    $post = $pdo->prepare('SELECT media_path FROM announcements WHERE id = ? FOR UPDATE');
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
// Only remove an unshared file inside the announcement upload directory.
// Historical/external media paths must never become arbitrary filesystem deletions.
try {
    $mediaPath = (string) ($row['media_path'] ?? '');
    if (preg_match('~\Auploads/announcements/announcement_[a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|gif|webp|mp4|webm)\z~D', $mediaPath)) {
        $references = $pdo->prepare('SELECT 1 FROM announcements WHERE media_path = ? LIMIT 1');
        $references->execute([$mediaPath]);
        if (!$references->fetchColumn()) {
            $directory = realpath(__DIR__ . '/../uploads/announcements');
            $file = realpath(__DIR__ . '/../' . $mediaPath);
            if ($directory !== false && $file !== false && dirname($file) === $directory && is_file($file)) {
                if (!@unlink($file)) error_log('Unable to remove deleted announcement attachment: ' . basename($file));
            }
        }
    }
} catch (Throwable $error) {
    error_log('Deleted announcement attachment cleanup failed: ' . $error->getMessage());
}
deletePostReply(200, 'Post deleted.', ['announcement_id' => $id]);
