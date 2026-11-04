<?php
require __DIR__ . '/../request/messenger-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    requestFail(405, 'Use the Forward Request button.');
}
if (!requestCanForward($requestActor)) requestFail(403, 'Only the Admin can forward requests.');
try {
    $id = requestInteger($_POST['request_id'] ?? null);
    $target = requestText($_POST, 'assigned_office', 20, true);
    $previous = requestText($_POST, 'expected_office', 20, true);
    if (!in_array($target, requestOffices(), true) || !in_array($previous, requestOffices(), true)) {
        throw new InvalidArgumentException('Choose Admin, Registrar, or OSA.');
    }
    $revision = filter_var($_POST['assignment_revision'] ?? null, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]);
    if ($revision === false || $revision === null) throw new InvalidArgumentException('Reload the request before forwarding it.');
    $nonce = requestToken($_POST, 'client_token');
    $pdo->beginTransaction();
    $row = requestRecord($pdo, $id, $requestActor, true);
    $retry = $pdo->prepare('SELECT new_office FROM request_assignment_history WHERE request_id = ? AND client_token = ?');
    $retry->execute([$id, $nonce]);
    $savedOffice = $retry->fetchColumn();
    if ($savedOffice !== false) {
        if ($savedOffice !== $target) {
            $pdo->rollBack();
            requestFail(409, 'This submission token was already used. Reopen the request.');
        }
        $duplicate = true;
    } else {
        if ($row['assigned_office'] !== $previous || (int) $row['assignment_revision'] !== $revision) {
            $pdo->rollBack();
            requestJson(409, ['message' => 'The assigned office changed. Review the current assignment before forwarding.',
                'request' => requestWithHistory($pdo, $row), 'role' => 'admin']);
        }
        if ($previous === $target) throw new InvalidArgumentException('Choose a different office.');
        $statement = $pdo->prepare('UPDATE requests SET assigned_office = ?, assignment_revision = assignment_revision + 1 WHERE id = ?');
        $statement->execute([$target, $id]);
        $statement = $pdo->prepare('INSERT INTO request_assignment_history
            (request_id, previous_office, new_office, reassigned_by_admin_id, client_token) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$id, $previous, $target, $requestActor['id'], $nonce]);
        $row['assigned_office'] = $target;
        $row['assignment_revision'] = $revision + 1;
        $duplicate = false;
    }
    $record = requestWithHistory($pdo, $row);
    $availability = requestOfficeAvailability($pdo);
    $warning = $availability[$row['assigned_office']] === 0
        ? 'No active ' . $row['assigned_office'] . ' account is available. The request remains saved in that office queue.' : '';
    $pdo->commit();
    requestJson(200, ['request' => $record, 'role' => 'admin', 'can_forward' => true,
        'duplicate' => $duplicate, 'routing_warning' => $warning,
        'message' => ($duplicate ? 'This forwarding action was already saved. Current office: ' : 'Request forwarded to ')
            . $row['assigned_office'] . '. Status and history are preserved.']);
} catch (Throwable $error) {
    requestException($error, $pdo);
}
