<?php
require __DIR__ . '/password_reset_common.php';

knsPasswordResetEndpoint(function (KnsPasswordResetService $service, array $data, array &$session, string $ip): array {
    $result = $service->verify(knsResetInput($data, 'otp', 6), $session, $ip);
    session_regenerate_id(true);
    return $result;
});