<?php
require __DIR__ . '/password_reset_common.php';

knsPasswordResetEndpoint(function (KnsPasswordResetService $service, array $data, array &$session, string $ip): array {
    $result = $service->reset(
        knsResetInput($data, 'password', 72), knsResetInput($data, 'password_confirmation', 72), $session, $ip
    );
    session_regenerate_id(true);
    return $result;
});