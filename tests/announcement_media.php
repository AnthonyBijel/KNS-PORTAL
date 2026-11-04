<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../php/announcement-common.php';
$checks = 0;
function mediaCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function mediaRejects(callable $action, string $label): void {
    try { $action(); } catch (InvalidArgumentException $error) { mediaCheck(true, $label); return; }
    throw new RuntimeException($label);
}
function uploadFixture(int $count, int $size = 1024): array {
    return ['error' => array_fill(0, $count, UPLOAD_ERR_OK), 'size' => array_fill(0, $count, $size), 'tmp_name' => array_fill(0, $count, 'temporary-upload')];
}
mediaCheck(knsAnnouncementUploads(null) === [], 'Text-only post');
mediaCheck(knsAnnouncementUploads(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']) === [], 'Empty legacy picker');
mediaCheck(knsAnnouncementUploads(['error' => [UPLOAD_ERR_NO_FILE], 'size' => [0], 'tmp_name' => ['']]) === [], 'Empty multiple picker');
mediaCheck(count(knsAnnouncementUploads(['error' => 0, 'size' => 100, 'tmp_name' => 'legacy'])) === 1, 'Legacy single upload');
mediaCheck(count(knsAnnouncementUploads(uploadFixture(3))) === 3, 'Multiple images normalized');
mediaCheck(count(knsAnnouncementUploads(uploadFixture(10, 2 * 1024 * 1024))) === 10, 'Ten images at exact total limit');
mediaRejects(fn() => knsAnnouncementUploads(uploadFixture(11)), 'Too many images rejected');
mediaRejects(fn() => knsAnnouncementUploads(uploadFixture(2, 11 * 1024 * 1024)), 'Combined size limit');
mediaRejects(fn() => knsAnnouncementUploads(uploadFixture(1, 0)), 'Empty file rejected');
mediaRejects(fn() => knsAnnouncementUploads(uploadFixture(1, 21 * 1024 * 1024)), 'Oversized file rejected');
foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE] as $error) {
    $upload = uploadFixture(2);
    $upload['error'][1] = $error;
    mediaRejects(fn() => knsAnnouncementUploads($upload), 'Any failed file rejects entire batch');
}
foreach ([[], 'invalid', ['error' => [0], 'size' => [], 'tmp_name' => ['x']], ['error' => [0], 'size' => [100], 'tmp_name' => [[]]]] as $upload) {
    mediaRejects(fn() => knsAnnouncementUploads($upload), 'Malformed upload rejected');
}
$legacy = ['id' => 1, 'media_path' => 'uploads/announcements/announcement_old.jpg', 'media_type' => 'image'];
mediaCheck(count(knsAnnouncementAttachments($legacy)) === 1, 'Legacy image remains visible');
mediaCheck(knsAnnouncementAttachments($legacy)[0]['url'] === '/kns_portal/' . $legacy['media_path'], 'Legacy URL unchanged');
mediaCheck(knsAnnouncementAttachments(['media_path' => 'javascript:alert(1)', 'media_type' => 'image']) === [], 'Unsafe legacy media hidden');
$items = [
    ['path' => 'uploads/announcements/announcement_one.jpg', 'type' => 'image'],
    ['path' => 'uploads/announcements/announcement_two.png', 'type' => 'image'],
    ['path' => 'uploads/announcements/announcement_three.webp', 'type' => 'image'],
];
$post = $legacy + ['media_attachments' => json_encode($items)];
$attachments = knsAnnouncementAttachments($post);
mediaCheck(count($attachments) === 3 && array_column($attachments, 'path') === array_column($items, 'path'), 'Gallery order preserved');
mediaCheck(str_contains(knsAnnouncementShareAttributes($post), 'data-media-url="/kns_portal/' . $items[0]['path'] . '"'), 'Share uses first gallery image');
foreach (['invalid-json', '{}', '[]'] as $manifest) {
    mediaCheck(count(knsAnnouncementAttachments($legacy + ['media_attachments' => $manifest])) === 1, 'Invalid manifest falls back');
}
$unsafe = [
    ['path' => 'javascript:alert(1)', 'type' => 'image'],
    ['path' => '../private.jpg', 'type' => 'image'],
    ['path' => '//example.com/image.jpg', 'type' => 'image'],
    ['path' => 'uploads/%2e%2e/private.jpg', 'type' => 'image'],
    ['path' => ['bad'], 'type' => 'image'],
    ['path' => 'uploads/x.jpg', 'type' => 'executable'],
    $items[0], $items[0],
];
mediaCheck(count(knsAnnouncementAttachments(['media_attachments' => json_encode($unsafe)])) === 1, 'Unsafe and duplicate entries omitted');
$video = knsAnnouncementAttachments(['media_path' => 'uploads/announcements/announcement_video.mp4', 'media_type' => 'video']);
mediaCheck(count($video) === 1 && $video[0]['type'] === 'video', 'Legacy video retained');
// Only four thumbnails are rendered; all URLs remain in the viewer payload.
$galleryItems = [];
for ($index = 0; $index < 7; $index++) {
    $galleryItems[] = ['url' => '/kns_portal/uploads/announcements/announcement_' . $index . '.jpg', 'type' => 'image'];
}
$galleryHtml = knsAnnouncementGallery($galleryItems, 'School "event" <test>', 'test-gallery');
$document = new DOMDocument();
@$document->loadHTML($galleryHtml);
$xpath = new DOMXPath($document);
mediaCheck($xpath->query('//img')->length === 4, 'Only four thumbnails are rendered');
mediaCheck($xpath->query('//a')->length === 4, 'Hidden images do not create extra tiles');
$galleryNode = $xpath->query('//div[@data-gallery-images]')->item(0);
mediaCheck(count(json_decode($galleryNode->getAttribute('data-gallery-images'), true)) === 7, 'Viewer retains all images');
$covered = $xpath->query('//a[@class="kns-gallery-covered"]')->item(0);
mediaCheck($covered !== null && $covered->textContent === '+3', 'Fourth thumbnail shows exact extra count');
mediaCheck($covered->getAttribute('data-gallery-index') === '4', 'Counter opens the first hidden image');
mediaCheck($galleryNode->getAttribute('data-gallery-title') === 'School "event" <test>', 'Gallery title remains safely escaped');
mediaCheck(!str_contains(knsAnnouncementGallery(array_slice($galleryItems, 0, 4), 'Four images', 'test'), 'kns-gallery-covered'), 'Four images have no extra counter');
mediaCheck(knsAnnouncementGallery([], 'No media', 'test') === '', 'No empty media wrapper');
mediaCheck(!str_contains(knsAnnouncementGallery([$galleryItems[0]], 'Single image', 'test'), 'data-gallery-images'), 'Single image link behavior retained');
mediaCheck(str_contains(knsAnnouncementGallery([['url' => '/kns_portal/test.mp4', 'type' => 'video']], 'Video', 'test'), '<video controls'), 'Single video remains playable');

require __DIR__ . '/../db.php';
// Shadow the table only on this CLI connection.
$definition = $pdo->query('SHOW CREATE TABLE announcements')->fetch(PDO::FETCH_NUM)[1];
$pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition, 1));
$insert = $pdo->prepare('INSERT INTO announcements (announcement_type, description, media_path, media_type, media_attachments) VALUES (?, ?, ?, ?, ?)');
$insert->execute(['Other', 'gallery-test', $items[0]['path'], 'image', json_encode($items)]);
$id = (int) $pdo->lastInsertId();
$stored = $pdo->query('SELECT * FROM announcements WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
mediaCheck(count(knsAnnouncementAttachments($stored)) === 3, 'Gallery survives database round trip');
$insert->execute(['Other', 'shared-file-test', $items[0]['path'], 'image', json_encode([$items[0], $items[2]])]);
$pdo->exec('DELETE FROM announcements WHERE id = ' . $id);
$references = $pdo->prepare("SELECT 1 FROM announcements WHERE media_path = ? OR JSON_SEARCH(COALESCE(media_attachments, '[]'), 'one', ?, '!', '$[*].path') IS NOT NULL LIMIT 1");
$references->execute([$items[2]['path'], strtr($items[2]['path'], ['!' => '!!', '%' => '!%', '_' => '!_'])]);
mediaCheck((bool) $references->fetchColumn(), 'Secondary gallery reference retained');
$references->execute([$items[1]['path'], strtr($items[1]['path'], ['!' => '!!', '%' => '!%', '_' => '!_'])]);
mediaCheck($references->fetchColumn() === false, 'Unshared gallery file can be cleaned up');
echo 'Passed ' . $checks . " media checks. Only a temporary table was used.\n";
