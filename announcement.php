<?php
require_once __DIR__ . '/php/announcement-common.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$post = false;
if ($id !== false && $id !== null) {
    require __DIR__ . '/db.php';
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1');
    $stmt->execute([$id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$post) {
    http_response_code(404);
}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$title = $post ? knsAnnouncementTitle($post) : 'Announcement not found';
$description = $post ? (string) ($post['description'] ?? '') : 'This announcement is no longer available.';
$mediaUrl = $post ? knsAnnouncementMediaUrl($post['media_path'] ?? '') : '';
$postUrl = knsAnnouncementOrigin() . '/kns_portal/announcement.php' . ($post ? '?id=' . (int) $post['id'] : '');
$imageUrl = knsAnnouncementAbsoluteUrl(
    $post && ($post['media_type'] ?? '') === 'image' && $mediaUrl !== ''
        ? $mediaUrl : '/kns_portal/asset/logo/273844945_520328949759168_1274024641438937791_n.jpg'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= knsAnnouncementEscape($title) ?> | Kolehiyo ng Subic</title>
    <meta name="description" content="<?= knsAnnouncementEscape($description) ?>">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= knsAnnouncementEscape($title) ?>">
    <meta property="og:description" content="<?= knsAnnouncementEscape($description) ?>">
    <meta property="og:image" content="<?= knsAnnouncementEscape($imageUrl) ?>">
    <meta property="og:url" content="<?= knsAnnouncementEscape($postUrl) ?>">
    <?php if ($post && ($post['media_type'] ?? '') === 'video' && $mediaUrl !== ''): ?>
        <meta property="og:video" content="<?= knsAnnouncementEscape(knsAnnouncementAbsoluteUrl($mediaUrl)) ?>">
    <?php endif; ?>
    <link rel="canonical" href="<?= knsAnnouncementEscape($postUrl) ?>">
    <link rel="stylesheet" href="/kns_portal/dashboard/dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard/dashboard.css') ?>">
    <link rel="stylesheet" href="/kns_portal/css/announcement.css?v=<?= filemtime(__DIR__ . '/css/announcement.css') ?>">
    <link rel="stylesheet" href="/kns_portal/dashboard/announcement-share.css?v=<?= filemtime(__DIR__ . '/dashboard/announcement-share.css') ?>">
</head>
<body>
    <main class="announcement-page">
        <a href="/kns_portal/login.php">Kolehiyo ng Subic Portal</a>
        <?php if ($post): ?>
            <div class="post-content" data-announcement-id="<?= (int) $post['id'] ?>">
                <div class="post-user-name">
                    <div class="post-logo">
                        <img class="post-profile" src="/kns_portal/asset/logo/273844945_520328949759168_1274024641438937791_n.jpg" alt="Kolehiyo ng Subic">
                    </div>
                    <div class="post-name">
                        <h1>kolehiyo ng Subic</h1>
                        <p><?= knsAnnouncementEscape(date('F j, Y g:i A', strtotime($post['created_at']))) ?></p>
                    </div>
                </div>
                <div class="description-box">
                    <p class="description-text expanded"><?= knsAnnouncementEscape($description) ?></p>
                </div>
                <?php if ($mediaUrl !== ''): ?>
                    <?php if ($post['media_type'] === 'image'): ?>
                        <div class="post-image">
                            <img src="<?= knsAnnouncementEscape($mediaUrl) ?>" alt="Announcement image">
                        </div>
                    <?php elseif ($post['media_type'] === 'video'): ?>
                        <div class="post-image">
                            <video controls preload="metadata">
                                <source src="<?= knsAnnouncementEscape($mediaUrl) ?>">
                            </video>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="share-button" <?= knsAnnouncementShareAttributes($post) ?> role="button" tabindex="0" aria-label="Share announcement" aria-haspopup="dialog">
                    <img src="/kns_portal/asset/logo/share-square.png" alt="">
                    <p>SHARE</p>
                </div>
            </div>
        <?php else: ?>
            <h1>Announcement not found</h1>
            <p>This announcement is no longer available.</p>
        <?php endif; ?>
    </main>
    <script src="/kns_portal/dashboard/announcement-share.js?v=<?= filemtime(__DIR__ . '/dashboard/announcement-share.js') ?>"></script>
</body>
</html>
