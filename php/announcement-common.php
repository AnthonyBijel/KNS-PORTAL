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

const KNS_ANNOUNCEMENT_MAX_IMAGES = 10;
const KNS_ANNOUNCEMENT_MAX_MEDIA_BYTES = 20 * 1024 * 1024;

function knsAnnouncementAttachments(array $post): array
{
    $manifest = $post['media_attachments'] ?? null;
    $items = is_string($manifest) ? json_decode($manifest, true) : null;
    $attachments = [];
    if (is_array($items) && array_is_list($items)) {
        foreach (array_slice($items, 0, KNS_ANNOUNCEMENT_MAX_IMAGES) as $item) {
            if (!is_array($item) || !is_string($item['path'] ?? null)
                || !in_array($item['type'] ?? null, ['image', 'video'], true)) continue;
            $url = knsAnnouncementMediaUrl($item['path']);
            if ($url !== '') $attachments[$url] = ['path' => $item['path'], 'type' => $item['type'], 'url' => $url];
        }
    }
    if ($attachments) return array_values($attachments);
    // Preserve existing single-image and video posts.
    $path = $post['media_path'] ?? '';
    $type = $post['media_type'] ?? '';
    $url = is_string($path) ? knsAnnouncementMediaUrl($path) : '';
    return $url !== '' && in_array($type, ['image', 'video'], true)
        ? [['path' => $path, 'type' => $type, 'url' => $url]] : [];
}

// Render four thumbnails while retaining the complete list for the viewer.
function knsAnnouncementGallery(array $attachments, string $title, string $class): string
{
    if (!$attachments) return '';
    $isGallery = count($attachments) > 1;
    $extra = count($attachments) - 4;
    $html = '<div class="' . knsAnnouncementEscape($class . ($isGallery ? ' kns-announcement-gallery' : ''))
        . '" role="group" aria-label="Post attachments"';
    if ($isGallery) {
        $html .= ' data-gallery-images="' . knsAnnouncementEscape(json_encode(array_column($attachments, 'url'), JSON_UNESCAPED_SLASHES))
            . '" data-gallery-title="' . knsAnnouncementEscape($title) . '"';
    }
    $html .= '>';
    foreach (array_slice($attachments, 0, 4) as $index => $attachment) {
        $url = knsAnnouncementEscape($attachment['url']);
        if ($attachment['type'] === 'video') {
            $html .= '<video controls preload="metadata" aria-label="' . knsAnnouncementEscape($title)
                . '"><source src="' . $url . '">Your browser cannot play this video. <a href="' . $url . '">Download video</a></video>';
            continue;
        }
        $covered = $index === 3 && $extra > 0;
        $openIndex = $covered ? 4 : $index;
        $openUrl = knsAnnouncementEscape($attachments[$openIndex]['url']);
        $label = $covered ? 'View ' . $extra . ' more images: ' . $title
            : 'View image ' . ($index + 1) . ' of ' . count($attachments) . ': ' . $title;
        $html .= '<a href="' . $openUrl . '" target="_blank" rel="noopener noreferrer"'
            . ($isGallery ? ' data-gallery-index="' . $openIndex . '" aria-haspopup="dialog"' : '')
            . ($covered ? ' class="kns-gallery-covered"' : '')
            . ' aria-label="' . knsAnnouncementEscape($label) . '"><img src="' . $url
            . '" alt="' . knsAnnouncementEscape('Image ' . ($index + 1) . ' for ' . $title) . '" loading="lazy">';
        if ($covered) $html .= '<span class="kns-gallery-more" aria-hidden="true">+' . $extra . '</span>';
        $html .= '</a>';
    }
    return $html . '</div>';
}

// Accept the multiple picker and older single-file forms.
function knsAnnouncementUploads($upload): array
{
    if ($upload === null) return [];
    if (!is_array($upload)) throw new InvalidArgumentException('Select valid attachments.');
    foreach (['error', 'size', 'tmp_name'] as $field) {
        if (!array_key_exists($field, $upload)) throw new InvalidArgumentException('Select valid attachments.');
    }
    if (!is_array($upload['error'])) {
        $upload = ['error' => [$upload['error']], 'size' => [$upload['size']], 'tmp_name' => [$upload['tmp_name']]];
    }
    $count = count($upload['error']);
    if ($count > KNS_ANNOUNCEMENT_MAX_IMAGES) throw new InvalidArgumentException('Choose up to 10 images or one video.');
    foreach (['size', 'tmp_name'] as $field) {
        if (!is_array($upload[$field]) || array_keys($upload[$field]) !== array_keys($upload['error'])) {
            throw new InvalidArgumentException('Select valid attachments.');
        }
    }
    $files = [];
    $total = 0;
    foreach ($upload['error'] as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE && $count === 1) return [];
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('An attachment could not be uploaded. Check its size and try again.');
        $size = $upload['size'][$index];
        $temporary = $upload['tmp_name'][$index];
        if (!is_int($size) || $size < 1 || $size > KNS_ANNOUNCEMENT_MAX_MEDIA_BYTES) {
            throw new InvalidArgumentException('Choose nonempty attachments totaling at most 20 MB.');
        }
        if (!is_string($temporary) || $temporary === '') throw new InvalidArgumentException('Select valid attachments.');
        $total += $size;
        $files[] = ['tmp_name' => $temporary, 'size' => $size];
    }
    if ($total > KNS_ANNOUNCEMENT_MAX_MEDIA_BYTES) throw new InvalidArgumentException('Attachments must total at most 20 MB.');
    return $files;
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
        'data-media-url' => knsAnnouncementAttachments($post)[0]['url'] ?? '',
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
