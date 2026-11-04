<?php
// Admin request decisions. Uses the same identity, CSRF, private files, and conversation.
require __DIR__ . '/../request/messenger-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    requestFail(405, 'Use the action buttons in Request Details.');
}
if ($requestActor['role'] !== 'admin') {
    requestFail(403, 'Only the administrator can update request statuses.');
}

$moved = [];
try {
    $requestId = requestInteger($_POST['request_id'] ?? null);
    $action = requestText($_POST, 'action', 20, true);
    $statuses = ['reject' => 'Rejected', 'processing' => 'Processing', 'approve' => 'Approved'];
    if (!isset($statuses[$action])) throw new InvalidArgumentException('Choose a valid request action.');
    $target = $statuses[$action];
    $expected = requestText($_POST, 'expected_status', 20, true);
    if (!in_array($expected, ['Pending', 'Processing', 'Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Invalid request status. Reopen Request Details.');
    }
    $assignmentRevision = $_POST['assignment_revision'] ?? null;
    if ($assignmentRevision !== null) {
        $assignmentRevision = filter_var($assignmentRevision, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($assignmentRevision === false) throw new InvalidArgumentException('Reload the request before updating its status.');
    }
    $remarks = requestText($_POST, 'remarks', 500);
    $nonce = requestToken($_POST, 'client_token');
    $files = requestUploadFiles('response_files');
    if ($target !== 'Approved' && $files) {
        throw new InvalidArgumentException('Use Approve & Send for response files, or remove the selected files before changing the status.');
    }
    foreach ($files as $file) {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (!isset($allowed[$extension]) || $file['mime'] !== $allowed[$extension]
            || ($extension !== 'pdf' && $file['preview'] === null)) {
            throw new InvalidArgumentException('Response files must be valid PDF, JPG, or PNG files (up to 3 files, 5 MB each).');
        }
    }

    $pdo->beginTransaction();
    // Forwarding, status changes, and Messenger sends share this per-request lock.
    $record = requestRecord($pdo, $requestId, $requestActor, true);
    $body = 'Request status: ' . $target . "\nReference: " . $record['reference_no'];
    if ($remarks !== '') $body .= "\n\n" . $remarks;

    // A failed connection can be retried without repeating the action or attachments.
    $stmt = $pdo->prepare('SELECT id, sender_role, sender_admin_id, body
        FROM request_messages WHERE request_id = ? AND client_token = ?');
    $stmt->execute([$requestId, $nonce]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($existing['sender_role'] !== 'admin'
            || (int) $existing['sender_admin_id'] !== $requestActor['id'] || $existing['body'] !== $body) {
            $pdo->rollBack();
            requestFail(409, 'This submission token was already used. Reopen Request Details.');
        }
        $pdo->commit();
        requestJson(200, ['request' => requestPublic($record), 'role' => 'admin',
            'message_id' => (int) $existing['id'], 'duplicate' => true,
            'message' => 'This action was already saved. Current status: ' . $record['status'] . '.']);
    }

    $conflict = null;
    if ($assignmentRevision !== null && (int) $record['assignment_revision'] !== $assignmentRevision) {
        $conflict = 'The assigned office changed. Reopen Request Details before updating the status.';
    } elseif ($record['status'] !== $expected) {
        $conflict = 'The request status changed. Current status: ' . $record['status'] . '. Review it before trying again.';
    } elseif (in_array($record['status'], ['Approved', 'Rejected'], true)) {
        $conflict = 'This request is already ' . strtolower($record['status']) . ' and cannot be changed.';
    } elseif ($record['status'] === $target) {
        $conflict = 'This request is already marked as ' . strtolower($target) . '.';
    }
    if ($conflict !== null) {
        $pdo->rollBack();
        requestJson(409, ['message' => $conflict, 'request' => requestPublic($record), 'role' => 'admin']);
    }

    $stmt = $pdo->prepare('UPDATE requests SET status = ? WHERE id = ? AND status = ?');
    $stmt->execute([$target, $requestId, $expected]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Request status update was not saved.');

    // This notification is sent by the signed-in admin's explicit button click.
    $stmt = $pdo->prepare("INSERT INTO request_messages
        (request_id, sender_role, sender_admin_id, sender_student_id, body, event_kind, event_status, client_token)
        VALUES (?, 'admin', ?, NULL, ?, 'request_status', ?, ?)");
    $stmt->execute([$requestId, $requestActor['id'], $body, $target, $nonce]);
    $messageId = (int) $pdo->lastInsertId();
    requestSaveAttachments($pdo, $requestId, $messageId, $files, $moved);
    $record['status'] = $target;
    $pdo->commit();

    requestJson(200, ['request' => requestPublic($record), 'role' => 'admin',
        'message_id' => $messageId, 'duplicate' => false,
        'message' => 'Request ' . $record['reference_no'] . ' marked as ' . $target .
            '. The response is available in Messenger.']);
} catch (Throwable $error) {
    requestException($error, $pdo, $moved);
}
