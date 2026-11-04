<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../php/announcement-common.php';
require __DIR__ . '/../db.php';
$checks = 0;
function expiryCheck(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function expiryRejects(callable $action, string $label): void {
    try { $action(); } catch (InvalidArgumentException $error) { expiryCheck(true, $label); return; }
    throw new RuntimeException($label);
}
$now = new DateTimeImmutable('2026-11-04 05:45:00', new DateTimeZone('UTC'));
expiryCheck(knsAnnouncementExpiration('none', '', $now) === null, 'No expiration retains NULL');
foreach (['1_day' => 1, '3_days' => 3, '7_days' => 7, '30_days' => 30] as $duration => $days) {
    $expiry = knsAnnouncementExpiration($duration, '', $now);
    expiryCheck($expiry->getTimestamp() - $now->getTimestamp() === $days * 86400, 'Preset duration ' . $duration);
    expiryCheck($expiry->getTimezone()->getName() === 'UTC', 'Preset stored in UTC');
}
$custom = knsAnnouncementExpiration('custom', '2026-11-04T14:00', $now);
expiryCheck($custom->format('Y-m-d H:i:s') === '2026-11-04 06:00:00', 'Custom Philippine time converts to UTC');
expiryCheck(knsAnnouncementExpiryIso('2026-11-04 06:00:00') === '2026-11-04T06:00:00Z', 'Browser deadline explicitly uses UTC');
expiryCheck(knsAnnouncementExpiryIso(null) === '', 'Legacy posts have no browser deadline');
foreach (['', '2026-11-04T13:45', '2026-11-04T13:44', '2026-02-30T14:00', '2026-11-04T25:00', '2026-11-04T14:00:00', '2026-11-04T14:00+08:00'] as $date) {
    expiryRejects(fn() => knsAnnouncementExpiration('custom', $date, $now), 'Invalid or nonfuture custom expiration rejected');
}
foreach (['forever', '-1', [], null] as $duration) {
    expiryRejects(fn() => knsAnnouncementExpiration($duration, '', $now), 'Invalid duration rejected');
}
expiryRejects(fn() => knsAnnouncementExpiration('custom', [], $now), 'Array custom date rejected');
// Shadow the real table only on this CLI connection. No persistent school posts are changed.
$definition = $pdo->query('SHOW CREATE TABLE announcements')->fetch(PDO::FETCH_NUM)[1];
$pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition, 1));
$pdo->exec("INSERT INTO announcements (announcement_type, description, expires_at) VALUES
    ('Other', 'no-expiry', NULL),
    ('Other', 'expired', UTC_TIMESTAMP() - INTERVAL 1 DAY),
    ('Other', 'at-deadline', UTC_TIMESTAMP()),
    ('Other', 'future', UTC_TIMESTAMP() + INTERVAL 1 DAY)");
foreach (['+00:00', '+08:00'] as $zone) {
    $pdo->exec("SET time_zone = '$zone'");
    $visible = $pdo->query('SELECT description FROM announcements WHERE (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY description')->fetchAll(PDO::FETCH_COLUMN);
    expiryCheck($visible === ['future', 'no-expiry'], 'Visibility and exact expiry boundary in timezone ' . $zone);
    $expiredId = $pdo->query("SELECT id FROM announcements WHERE description = 'expired'")->fetchColumn();
    $stmt = $pdo->prepare('SELECT id FROM announcements WHERE id = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())');
    $stmt->execute([$expiredId]);
    expiryCheck($stmt->fetchColumn() === false, 'Expired direct lookup is unavailable');
}
expiryCheck((int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn() === 4, 'Expiration hides without deleting records');
echo 'Passed ' . $checks . " expiration checks. Only a temporary table was used.\n";
