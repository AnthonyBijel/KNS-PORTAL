<?php
// Environment variables take precedence over the private file outside htdocs.
$privatePath = (string) (getenv('KNS_PASSWORD_RESET_CONFIG')
    ?: dirname(__DIR__, 3) . '/kns_portal_private/password-reset.php');
$private = [];
if (is_file($privatePath)) {
    $resolved = realpath($privatePath);
    $publicRoot = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2));
    if ($publicRoot !== false && $resolved !== false) {
        $normalizedPath = strtolower(str_replace('\\', '/', $resolved));
        $normalizedRoot = rtrim(strtolower(str_replace('\\', '/', $publicRoot)), '/');
        if ($normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            throw new RuntimeException('Recovery credentials must be outside the public directory.');
        }
    }
    $private = require $privatePath;
    if (!is_array($private)) throw new RuntimeException('Invalid private recovery configuration.');
} elseif (getenv('KNS_PASSWORD_RESET_CONFIG')) {
    throw new RuntimeException('Private recovery configuration is missing.');
}

$read = static function (string $environmentName, string $key) use ($private): string {
    $value = getenv($environmentName);
    $value = $value !== false && $value !== '' ? $value : ($private[$key] ?? '');
    if (!is_string($value)) throw new RuntimeException('Invalid recovery configuration value.');
    return trim($value);
};
$username = $read('KNS_SMTP_USERNAME', 'smtp_username');
return [
    'smtp_username' => $username,
    'smtp_password' => str_replace(' ', '', $read('KNS_SMTP_PASSWORD', 'smtp_password')),
    'smtp_from' => $read('KNS_SMTP_FROM_EMAIL', 'smtp_from') ?: $username,
    'twilio_sid' => $read('KNS_TWILIO_ACCOUNT_SID', 'twilio_sid'),
    'twilio_token' => $read('KNS_TWILIO_AUTH_TOKEN', 'twilio_token'),
    'twilio_from' => $read('KNS_TWILIO_FROM_NUMBER', 'twilio_from'),
];