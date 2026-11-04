<?php

function knsAnnouncementEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function knsAnnouncementTitle(array $post): string
{
    $title = trim((string) ($post['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    // Existing announcements have a category and description, but no title column.
    $category = trim((string) ($post['announcement_type'] ?? ''));
    return $category !== '' ? $category . ' announcement' : 'Kolehiyo ng Subic announcement';
}

function knsAnnouncementMediaUrl($value): string
{
    $path = trim((string) ($value ?? ''));
    if ($path === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $path)) {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        return filter_var($path, FILTER_VALIDATE_URL) !== false ? $path : '';
    }

    // Only local paths or explicit HTTP(S) URLs are allowed.
    if (str_starts_with($path, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
        return '';
    }

    $path = ltrim($path, '/');
    if (str_starts_with($path, 'kns_portal/')) {
        $path = substr($path, strlen('kns_portal/'));
    }
    $segments = explode('/', $path);
    foreach ($segments as &$segment) {
        $segment = rawurldecode($segment);
        if ($segment === '' || $segment === '.' || $segment === '..'
            || preg_match('~[\x00-\x1F\x7F/\\\\]~', $segment)) {
            return '';
        }
        $segment = rawurlencode($segment);
    }
    unset($segment);

    return '/kns_portal/' . implode('/', $segments);
}

function knsAnnouncementOrigin(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z/i', $host)) {
        $host = 'localhost';
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https://' : 'http://') . $host;
}

function knsAnnouncementAbsoluteUrl(string $url): string
{
    return preg_match('~^https?://~i', $url) ? $url : knsAnnouncementOrigin() . $url;
}

function knsAnnouncementShareAttributes(array $post): string
{
    $values = [
        'data-announcement-id' => (int) $post['id'],
        'data-title' => knsAnnouncementTitle($post),
        'data-description' => $post['description'] ?? '',
        'data-media-url' => knsAnnouncementMediaUrl($post['media_path'] ?? ''),
    ];
    $attributes = [];
    foreach ($values as $name => $value) {
        $attributes[] = $name . '="' . knsAnnouncementEscape($value) . '"';
    }
    return implode(' ', $attributes);
}

// Expiration values are stored as UTC DATETIME, independent of each DB connection timezone.
function knsAnnouncementExpiration($duration, $custom = '', ?DateTimeImmutable $now = null): ?DateTimeImmutable
{
    if (!is_string($duration) || !is_string($custom)) throw new InvalidArgumentException('Select a valid post duration.');
    $zone = new DateTimeZone('Asia/Manila');
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);
    if ($duration === 'none') return null;
    $days = ['1_day' => 1, '3_days' => 3, '7_days' => 7, '30_days' => 30];
    if (isset($days[$duration])) {
        return $now->modify('+' . $days[$duration] . ' days')->setTimezone(new DateTimeZone('UTC'));
    }
    if ($duration !== 'custom') throw new InvalidArgumentException('Select a valid post duration.');
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $custom)) {
        throw new InvalidArgumentException('Choose an expiration date and time in Philippine time.');
    }
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $custom, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$expiry || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $expiry->format('Y-m-d\TH:i') !== $custom || $expiry <= $now) {
        throw new InvalidArgumentException('Expiration must be a valid future date and time in Philippine time.');
    }
    return $expiry->setTimezone(new DateTimeZone('UTC'));
}

function knsAnnouncementExpiryIso(?string $value): string
{
    return $value === null || $value === '' ? ''
        : (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}
