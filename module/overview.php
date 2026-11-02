<?php
require __DIR__ . "/../db.php";
require_once __DIR__ . '/../php/announcement-common.php';

$stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories = ["All", "Academic", "Events", "Guidelines", "Others"];
?>

<div class="greeting-contianer">
    <div class="welcome-text-box">
        <p class="date-layer">ANNOUNCEMENT</p>
        <h1 class="greet-layer">Stay Informed.</h1>
        <p class="request-review">Get the latest updates, events, and important announcement from Kolehiyo ng Subic</p>
    </div>
</div>

<div class="announcement-type-selection" role="group" aria-label="Filter announcements">
    <?php foreach ($categories as $category): ?>
        <button type="button"
                class="announcement-type<?= $category === 'All' ? ' selected' : '' ?>"
                data-category="<?= strtolower($category) ?>"
                aria-pressed="<?= $category === 'All' ? 'true' : 'false' ?>">
            <span><?= $category ?></span>
        </button>
    <?php endforeach; ?>
</div>

<div class="announcement-content">
    <?php foreach ($announcements as $index => $post): ?>
        <?php
        $postMediaUrl = knsAnnouncementMediaUrl($post['media_path'] ?? '');
        $postCategory = strtolower(trim($post['announcement_type'] ?? ''));
        if (!in_array($postCategory, ['academic', 'events', 'guidelines'], true)) {
            $postCategory = 'others';
        }
        $descriptionId = 'announcement-description-' . $index;
        ?>
        <div class="post-content" data-category="<?= $postCategory ?>" data-announcement-id="<?= (int) $post['id'] ?>">
            <div class="post-user-name">
                <div class="post-logo">
                    <img class="post-profile" src="../asset/logo/273844945_520328949759168_1274024641438937791_n.jpg" alt="Kolehiyo ng Subic">
                </div>
                <div class="post-name">
                    <h1>kolehiyo ng Subic</h1>
                    <p><?= date('F j, Y g:i A', strtotime($post['created_at'])) ?></p>
                </div>
            </div>

            <div class="description-box">
                <p class="description-text" id="<?= $descriptionId ?>"><?= knsAnnouncementEscape($post['description'] ?? '') ?></p>
                <button type="button" class="see-more-btn" aria-expanded="false" aria-controls="<?= $descriptionId ?>">See more</button>
            </div>

            <?php if ($postMediaUrl !== ''): ?>
                <?php if ($post['media_type'] === 'image'): ?>
                    <div class="post-image">
                        <img src="<?= knsAnnouncementEscape($postMediaUrl) ?>" alt="Announcement image">
                    </div>
                <?php elseif ($post['media_type'] === 'video'): ?>
                    <div class="post-image">
                        <video controls preload="metadata">
                            <source src="<?= knsAnnouncementEscape($postMediaUrl) ?>">
                        </video>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="share-button" <?= knsAnnouncementShareAttributes($post) ?> role="button" tabindex="0" aria-label="Share announcement" aria-haspopup="dialog">
                <img src="../asset/logo/share-square.png" alt="">
                <p>SHARE</p>
            </div>
        </div>
    <?php endforeach; ?>
    <p class="announcement-empty" role="status"<?= count($announcements) > 0 ? ' hidden' : '' ?>>No announcements yet.</p>
</div>
