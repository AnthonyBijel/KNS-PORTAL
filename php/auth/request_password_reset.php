<?php
require __DIR__ . '/password_reset_common.php';

knsPasswordResetEndpoint(function (KnsPasswordResetService $service, array $data, array &$session, string $ip): array {
    $action = knsResetInput($data, 'action', 10);
    $number = knsResetStudentNumber($data);
    if ($action === 'lookup') return $service->lookup($number, $session, $ip);
    if ($action === 'send') return $service->request($number, knsResetInput($data, 'channel', 10), $session, $ip);
    throw new KnsPasswordResetError('Choose a valid recovery action.');
});