<?php
require_once __DIR__ . '/../php/auth/session.php';
requireStudent();
require __DIR__ . "/../db.php";
require_once __DIR__ . '/../php/announcement-common.php';

$stmt = $pdo->query("SELECT * FROM announcements WHERE (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY created_at DESC");
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories = ["All", "Academic", "Events", "Guidelines", "Others"];
$graceQuery = $pdo->prepare('SELECT * FROM students WHERE id = ?');
$graceQuery->execute([currentStudentId()]);
$graceNotice = knsStudentGraceNotice($graceQuery->fetch(PDO::FETCH_ASSOC));

?>

<?php if ($graceNotice): ?><p role="status"><?= knsAnnouncementEscape($graceNotice) ?></p><?php endif; ?>
<div class="greeting-contianer">
    <div class="welcome-text-box">
        <p class="date-layer">ANNOUNCEMENT</p>
        <h1 class="greet-layer">Stay Informed.</h1>
        <p class="request-review">Get the latest updates, events, and important announcement from Kolehiyo ng Subic</p>
    </div>
</div>

<link rel="stylesheet" href="../dashboard/student-announcements.css?v=<?= filemtime(__DIR__ . '/../dashboard/student-announcements.css') ?>">
<section class="student-announcements" aria-labelledby="student-announcements-heading">
    <div class="sa-toolbar">
        <h2 id="student-announcements-heading"><span class="sa-heading-icon"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m4 10 12-5v14L4 14zM4 10H2v4h2m3 1 1 6h3l-2-5M20 9l2-1m-2 7 2 1M20 12h3"/></svg></span>Announcements</h2>
        <form class="sa-search" role="search" aria-label="Search announcements">
            <label><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/></svg><span class="sa-sr-only">Search posts</span><input type="search" data-announcement-search placeholder="Search posts..." autocomplete="off" aria-describedby="sa-search-help"></label>
        </form>
        <label class="sa-sort"><span class="sa-sr-only">Sort announcements</span><select data-announcement-sort><option value="newest">Newest</option><option value="oldest">Oldest</option></select></label>
    </div>
    <p id="sa-search-help" class="sa-sr-only">Search titles, Highlights, and descriptions. Press Enter to apply your search.</p>
    <div class="announcement-type-selection" role="group" aria-label="Filter announcements">
        <?php foreach ($categories as $category): ?>
            <button type="button" class="announcement-type<?= $category === 'All' ? ' selected' : '' ?>" data-category="<?= strtolower($category) ?>" aria-pressed="<?= $category === 'All' ? 'true' : 'false' ?>"><span><?= $category ?></span></button>
        <?php endforeach; ?>
    </div>
    <p class="sa-sr-only" data-announcement-status role="status" aria-live="polite"></p>
    <div class="announcement-content" data-server-time="<?= (int) round(microtime(true) * 1000) ?>">
        <?php foreach ($announcements as $index => $post): ?>
            <?php
            $attachments = knsAnnouncementAttachments($post);
            $postCategory = strtolower(trim($post['announcement_type'] ?? ''));
            if ($postCategory === 'event') $postCategory = 'events';
            if (!in_array($postCategory, ['academic', 'events', 'guidelines'], true)) $postCategory = 'others';
            $postType = ['academic' => 'Academic', 'events' => 'Event', 'guidelines' => 'Guidelines', 'others' => 'Other'][$postCategory];
            $postTitle = knsAnnouncementTitle($post);
            $postHighlights = trim((string) ($post['highlights'] ?? ''));
            $postDescription = (string) ($post['description'] ?? '');
            $descriptionId = 'announcement-description-' . $index;
            $postTime = new DateTimeImmutable($post['created_at'], new DateTimeZone('Asia/Manila'));
            $hasMedia = count($attachments) > 0;
            $hasGallery = count($attachments) > 1;
            ?>
            <article class="post-content" data-expires-at="<?= knsAnnouncementEscape(knsAnnouncementExpiryIso($post['expires_at'] ?? null)) ?>" data-category="<?= $postCategory ?>" data-announcement-id="<?= (int) $post['id'] ?>" data-post-timestamp="<?= $postTime->getTimestamp() ?>" data-search-text="<?= knsAnnouncementEscape($postTitle . ' ' . $postHighlights . ' ' . $postDescription) ?>" aria-labelledby="student-post-title-<?= (int) $post['id'] ?>">
                <div class="post-user-name">
                    <img class="post-profile" src="../asset/logo/273844945_520328949759168_1274024641438937791_n.jpg" alt="" width="42" height="42">
                    <div class="sa-publisher"><strong>Kolehiyo ng Subic</strong><div class="sa-post-meta"><time datetime="<?= knsAnnouncementEscape($postTime->format(DateTimeInterface::ATOM)) ?>"><?= knsAnnouncementEscape($postTime->format('M j, Y')) ?> &middot; <?= knsAnnouncementEscape($postTime->format('g:i A')) ?></time><span class="sa-meta-divider" aria-hidden="true">|</span><span class="sa-type-badge"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <?php if ($postCategory === 'academic'): ?><path d="M12 5C8 2 3 3 2 4v15c4-2 7-1 10 1 3-2 6-3 10-1V4c-2-1-7-2-10 1zm0 0v15"/>
                        <?php elseif ($postCategory === 'events'): ?><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6m10-6v6M3 11h18"/>
                        <?php elseif ($postCategory === 'guidelines'): ?><rect x="5" y="3" width="15" height="18" rx="2"/><path d="M9 7h7m-7 5h7m-7 5h5M2 6v12"/>
                        <?php else: ?><path d="m4 10 13-5v14L4 14zM4 10H2v4h2m3 1 1 6h3l-2-5M20 9l2-1m-2 7 2 1"/><?php endif; ?>
                    </svg><?= $postType ?></span></div></div>
                </div>
                <div class="sa-card-body<?= $hasGallery ? ' sa-with-gallery' : ($hasMedia ? ' sa-with-media' : '') ?>">
                    <div class="sa-card-copy">
                        <h3 id="student-post-title-<?= (int) $post['id'] ?>"><?= knsAnnouncementEscape($postTitle) ?></h3>
                        <?php if ($postHighlights !== ''): ?><div class="sa-highlights"><span class="sa-highlights-label">HIGHLIGHTS</span><p><?= knsAnnouncementEscape($postHighlights) ?></p></div><?php endif; ?>
                        <div class="description-box"><p class="description-text" id="<?= $descriptionId ?>"><?= knsAnnouncementEscape($postDescription) ?></p><button type="button" class="see-more-btn" aria-expanded="false" aria-controls="<?= $descriptionId ?>">See more</button></div>
                    </div>
                    <?= knsAnnouncementGallery($attachments, $postTitle, 'post-image' . ($hasGallery ? ' sa-media-gallery' : '')) ?>
                </div>
                <div class="sa-card-footer"><button type="button" class="share-button" <?= knsAnnouncementShareAttributes($post) ?> aria-label="Share <?= knsAnnouncementEscape($postTitle) ?>" aria-haspopup="dialog"><img src="../asset/logo/share-square.png" alt=""><span>Share</span></button></div>
            </article>
        <?php endforeach; ?>
        <p class="announcement-empty" role="status"<?= count($announcements) > 0 ? ' hidden' : '' ?>>No announcements yet.</p>
    </div>
</section>
