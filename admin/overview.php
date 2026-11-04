<?php
require_once __DIR__ . '/../php/auth/staff-access.php';
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../php/announcement-common.php';
$overviewAdmin = knsRequireStaffAccess($pdo, 'overview');
$announcements = $pdo->query('SELECT * FROM announcements WHERE (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$totalAnnouncements = count($announcements);
$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$hour = (int) $now->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$submissionToken = bin2hex(random_bytes(24));
$_SESSION['announcement_submissions'] ??= [];
$_SESSION['announcement_submissions'][$submissionToken] = null;
$_SESSION['announcement_submissions'] = array_slice($_SESSION['announcement_submissions'], -100, null, true);
$schoolName = trim($overviewAdmin['school_name']) ?: 'Kolehiyo ng Subic';
$published = ($_GET['posted'] ?? '') === 'success';
?>
<link rel="stylesheet" href="overview.css?v=<?= filemtime(__DIR__ . '/overview.css') ?>">
<section class="kns-overview" aria-label="Admin overview" data-server-time="<?= (int) round(microtime(true) * 1000) ?>">
    <div class="kns-welcome">
        <svg class="kns-welcome-photo" viewBox="1030 104 608 124" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
            <image href="../asset/overview/welcome-reference.png" width="1672" height="941" />
        </svg>
        <div class="kns-welcome-copy">
            <h1><?= knsAnnouncementEscape($greeting . ', ' . $overviewAdmin['admin_name'] . '.') ?></h1>
            <p>Manage announcements, update information,<br>and keep the school community informed.</p>
        </div>
    </div>
    <div class="kns-statistics" aria-label="Portal totals">
        <div class="kns-stat-card"><img src="../asset/logo/megaphone.png" alt="" width="32" height="32"><div><strong data-total-posts><?= number_format($totalAnnouncements) ?></strong><span>Total Posts</span></div></div>
        <div class="kns-stat-card"><img src="../asset/logo/people.png" alt="" width="32" height="32"><div><strong><?= number_format($totalStudents) ?></strong><span>Total Students</span></div></div>
    </div>
    <h2 class="kns-feed-heading"><img src="../asset/logo/megaphone.png" alt="" width="30" height="30">Announcements</h2>
    <div class="kns-post-toolbar" role="search" aria-label="Search announcements">
        <label class="kns-search-box">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/></svg>
            <span class="kns-sr-only">Search title, Highlights, and description</span><input type="search" data-post-search placeholder="Search posts..." autocomplete="off">
        </label>
        <label class="kns-type-filter"><span class="kns-sr-only">Filter by post type</span><select data-post-filter><option value="">All Post Types</option><option value="Academic">Academic</option><option value="Event">Event</option><option value="Guidelines">Guidelines</option><option value="Other">Other</option></select></label>
        <button class="kns-primary kns-create-button" type="button" data-create-post><span aria-hidden="true">+</span>Create Post</button>
    </div>
    <p class="kns-feed-status" data-feed-status role="status" aria-live="polite"></p>
    <p class="kns-publish-status" data-publish-status role="status" <?= $published ? '' : 'hidden' ?>><?= $published ? 'Your post was published.' : '' ?></p>
    <div class="kns-post-feed">
        <?php foreach ($announcements as $post): ?>
            <?php
            $title = knsAnnouncementTitle($post);
            $highlights = trim((string) ($post['highlights'] ?? ''));
            $description = (string) ($post['description'] ?? '');
            $type = trim((string) $post['announcement_type']);
            $type = $type === 'Events' ? 'Event' : $type;
            if (!in_array($type, ['Academic', 'Event', 'Guidelines', 'Other'], true)) $type = 'Other';
            $tone = strtolower($type);
            $attachments = knsAnnouncementAttachments($post);
            $hasMedia = count($attachments) > 0;
            $hasGallery = count($attachments) > 1;
            $postTime = new DateTimeImmutable($post['created_at'], new DateTimeZone('Asia/Manila'));
            ?>
            <article class="kns-post-card kns-tone-<?= $tone ?>" data-post-card data-announcement-id="<?= (int) $post['id'] ?>" data-expires-at="<?= knsAnnouncementEscape(knsAnnouncementExpiryIso($post['expires_at'] ?? null)) ?>" data-post-type="<?= $type ?>" data-search-text="<?= knsAnnouncementEscape($title . ' ' . $highlights . ' ' . $description) ?>">
                <div class="kns-post-author">
                    <img class="kns-post-avatar" src="../asset/logo/273844945_520328949759168_1274024641438937791_n.jpg" alt="" width="44" height="44">
                    <div><p class="kns-author-name"><?= knsAnnouncementEscape($schoolName) ?></p><div class="kns-post-meta"><time datetime="<?= knsAnnouncementEscape($postTime->format(DateTimeInterface::ATOM)) ?>"><?= knsAnnouncementEscape($postTime->format('M j, Y, g:i A')) ?></time><span aria-hidden="true">&middot;</span><span class="kns-type-badge"><?= $type ?></span></div></div>
                    <button type="button" class="kns-delete-icon" data-delete-post title="Delete post" aria-label="Delete <?= knsAnnouncementEscape($title) ?>" aria-haspopup="dialog" aria-controls="kns-delete-confirmation">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5M14 11v5"/></svg>
                    </button>
                </div>
                <div class="kns-post-body <?= $hasGallery ? 'kns-with-gallery' : ($hasMedia ? 'kns-with-media' : '') ?>">
                    <div class="kns-post-copy">
                        <h3><?= knsAnnouncementEscape($title) ?></h3>
                        <?php if ($highlights !== ''): ?><div class="kns-highlights"><img src="../asset/logo/<?= $type === 'Event' ? 'calendar-clock.png' : ($type === 'Academic' ? 'graduate-hat.png' : 'megaphone.png') ?>" alt="" width="23" height="23"><p><span class="kns-sr-only">Highlights: </span><?= knsAnnouncementEscape($highlights) ?></p></div><?php endif; ?>
                        <p class="kns-description" id="post-description-<?= (int) $post['id'] ?>"><?= knsAnnouncementEscape($description) ?></p>
                        <button type="button" class="kns-see-more" aria-controls="post-description-<?= (int) $post['id'] ?>" aria-expanded="false" hidden>See more</button>
                    </div>
                    <?= knsAnnouncementGallery($attachments, $title, 'kns-post-media' . ($hasGallery ? ' kns-media-gallery' : '')) ?>
                </div>
                <div class="kns-post-actions">
                    <?php if (!empty($post['expires_at'])): ?><span class="kns-post-expiry">Visible until <time datetime="<?= knsAnnouncementEscape(knsAnnouncementExpiryIso($post['expires_at'])) ?>"><?= knsAnnouncementEscape((new DateTimeImmutable($post['expires_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y, g:i A')) ?></time> (PH)</span><?php endif; ?><button type="button" class="share-button kns-share-button" <?= knsAnnouncementShareAttributes($post) ?> aria-label="Share <?= knsAnnouncementEscape($title) ?>" aria-haspopup="dialog"><img src="../asset/logo/share-square.png" alt="">Share</button></div>
            </article>
        <?php endforeach; ?>
        <div class="kns-empty-state" data-post-empty <?= $totalAnnouncements ? 'hidden' : '' ?>><strong><?= $totalAnnouncements ? 'No matching posts' : 'No announcements yet' ?></strong><p><?= $totalAnnouncements ? 'Try a different search or post type.' : 'Create a post to keep your school community informed.' ?></p></div>
    </div>
    <dialog class="kns-post-modal" aria-labelledby="kns-create-title" aria-describedby="kns-create-help">
        <div class="kns-modal-heading"><div><h2 id="kns-create-title">Create Post</h2><p id="kns-create-help">Share an announcement with your school community.</p></div><button type="button" class="kns-modal-close" data-close-post aria-label="Close Create Post">&times;</button></div>
        <form action="create_post.php" method="post" enctype="multipart/form-data" data-post-form>
            <input type="hidden" name="csrf" value="<?= knsAnnouncementEscape($_SESSION['csrf']) ?>"><input type="hidden" name="submission_token" value="<?= knsAnnouncementEscape($submissionToken) ?>">
            <fieldset>
                <label for="kns-post-type">Post type <span aria-hidden="true">*</span></label><select id="kns-post-type" name="announcement_type" required autofocus><option value="">Select a post type</option><option value="Academic">Academic</option><option value="Event">Event</option><option value="Guidelines">Guidelines</option><option value="Other">Other</option></select>
                <label for="kns-post-duration">Post duration <span aria-hidden="true">*</span></label>
                <select id="kns-post-duration" name="duration" required aria-describedby="kns-duration-help">
                    <option value="none">No expiration</option><option value="1_day">1 day</option><option value="3_days">3 days</option><option value="7_days">7 days</option><option value="30_days">30 days</option><option value="custom">Custom date and time</option>
                </select>
                <small class="kns-duration-help" id="kns-duration-help">Expired posts are automatically hidden from the portal.</small>
                <div class="kns-custom-expiration" data-custom-expiration hidden>
                    <label for="kns-post-expires">Expires on <span aria-hidden="true">*</span></label>
                    <input type="datetime-local" id="kns-post-expires" name="expires_at" min="<?= $now->modify('+1 minute')->format('Y-m-d\TH:i') ?>" disabled aria-describedby="kns-expiration-help">
                    <small class="kns-duration-help" id="kns-expiration-help">Philippine time (Asia/Manila).</small>
                </div>
                <label for="kns-post-title">Title <span aria-hidden="true">*</span></label><input id="kns-post-title" name="title" maxlength="200" required placeholder="Give your announcement a clear title">
                <label for="kns-post-highlights">Highlights <span aria-hidden="true">*</span></label><textarea id="kns-post-highlights" name="highlights" rows="2" maxlength="1000" required placeholder="The key details everyone should see"></textarea>
                <label for="kns-post-description">Full description <span aria-hidden="true">*</span></label><textarea id="kns-post-description" name="description" rows="4" maxlength="20000" required placeholder="Write your full announcement here..."></textarea>
                <label for="mediaInput">Images or video <span class="kns-optional">(optional)</span></label><div class="kns-attachment"><input type="file" id="mediaInput" name="media[]" multiple accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" aria-describedby="kns-media-help"><small id="kns-media-help">Up to 10 images (JPG, PNG, GIF, WEBP) or one video (MP4, WEBM). Maximum 20 MB total, subject to the server upload limit. Choose more images to add to your selection.</small><p data-media-message role="status"></p><div id="mediaPreview"></div></div>
            </fieldset>
            <p class="kns-form-message" data-post-message role="alert"></p>
            <div class="kns-modal-actions"><button type="button" class="kns-secondary" data-close-post>Cancel</button><button type="submit" class="kns-primary">Publish Post</button></div>
        </form>
    </dialog>
    <dialog class="kns-delete-modal" id="kns-delete-confirmation" aria-labelledby="kns-delete-heading" aria-describedby="kns-delete-help">
        <form action="delete_post.php" method="post" data-delete-form>
            <input type="hidden" name="csrf" value="<?= knsAnnouncementEscape($_SESSION['csrf']) ?>">
            <input type="hidden" name="announcement_id" value="">
            <input type="hidden" name="confirm_delete" value="1">
            <div class="kns-modal-heading"><h2 id="kns-delete-heading">Delete this post?</h2><button type="button" class="kns-modal-close" data-cancel-delete aria-label="Cancel deletion">&times;</button></div>
            <p id="kns-delete-help">Permanently delete <strong data-delete-title></strong>? This cannot be undone.</p>
            <p class="kns-form-message" data-delete-message role="alert"></p>
            <div class="kns-modal-actions"><button type="button" class="kns-secondary" data-cancel-delete autofocus>Cancel</button><button type="submit" class="kns-delete-confirm">Delete Post</button></div>
        </form>
    </dialog>
</section>
