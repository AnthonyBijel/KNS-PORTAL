<?php
require __DIR__ . '/messenger-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    requestFail(405, 'Use GET to download attachments.');
}
try {
    $id = requestInteger($_GET['id'] ?? null);
    $stmt = $pdo->prepare('SELECT * FROM request_attachments WHERE id = ?');
    $stmt->execute([$id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) requestFail(404, 'Attachment not found.');
    // Includes owning student check on every download and every image preview.
    requestRecord($pdo, (int) $file['request_id'], $requestActor);
    if (!preg_match('/^[a-f0-9]{64}$/D', $file['storage_key'])) requestFail(404, 'Attachment unavailable.');
    $path = requestStorage() . DIRECTORY_SEPARATOR . $file['storage_key'];
    if (!is_file($path) || !is_readable($path)) requestFail(404, 'Attachment unavailable.');
    $stream = fopen($path, 'rb');
    if ($stream === false) requestFail(404, 'Attachment unavailable.');
    $preview = ($_GET['preview'] ?? '') === '1'
        && in_array($file['preview_mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    $name = $file['original_name'];
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
    header('Content-Type: ' . ($preview ? $file['preview_mime'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') .
        '; filename="' . $fallback . '"; filename*=UTF-8' . "''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');
    fpassthru($stream);
    fclose($stream);
    exit;
} catch (Throwable $error) {
    requestException($error, $pdo);
}
