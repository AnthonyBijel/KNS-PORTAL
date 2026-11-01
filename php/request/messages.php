<?php
require __DIR__ . '/messenger-common.php';
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    requestFail(405, 'Use GET to read or POST to send messages.');
}
$moved = [];
try {
    $requestId = requestInteger(($method === 'POST' ? $_POST : $_GET)['request_id'] ?? null);
    if ($method === 'POST') {
        $body = requestText($_POST, 'body', 5000);
        $nonce = requestToken($_POST, 'client_token');
        $files = requestUploadFiles();
        if ($body === '' && !$files) throw new InvalidArgumentException('Write a message or attach a file.');
        $pdo->beginTransaction();
        // Per-request lock keeps message IDs in commit order for incremental polling.
        requestRecord($pdo, $requestId, $requestActor, true);
        $stmt = $pdo->prepare('SELECT id, sender_role, sender_admin_id, sender_student_id
            FROM request_messages WHERE request_id = ? AND client_token = ?');
        $stmt->execute([$requestId, $nonce]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $senderId = $existing[$requestActor['role'] === 'admin' ? 'sender_admin_id' : 'sender_student_id'];
            if ($existing['sender_role'] !== $requestActor['role'] || (int) $senderId !== $requestActor['id']) {
                $pdo->rollBack();
                requestFail(409, 'Submission token already used. Reload this conversation.');
            }
            $id = (int) $existing['id'];
        } else {
            $stmt = $pdo->prepare('INSERT INTO request_messages
                (request_id, sender_role, sender_admin_id, sender_student_id, body, client_token)
                VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$requestId, $requestActor['role'],
                $requestActor['role'] === 'admin' ? $requestActor['id'] : null,
                $requestActor['role'] === 'student' ? $requestActor['id'] : null, $body, $nonce]);
            $id = (int) $pdo->lastInsertId();
            requestSaveAttachments($pdo, $requestId, $id, $files, $moved);
        }
        $pdo->commit();
        requestJson(201, ['message_id' => $id]);
    }
    $record = requestPublic(requestRecord($pdo, $requestId, $requestActor));
    $params = [$requestId];
    $condition = '';
    $ascending = false;
    if (isset($_GET['after'])) {
        $condition = ' AND m.id > ?';
        $params[] = requestInteger($_GET['after']);
        $ascending = true;
    } elseif (isset($_GET['before'])) {
        $condition = ' AND m.id < ?';
        $params[] = requestInteger($_GET['before']);
    }
    $stmt = $pdo->prepare('SELECT m.id, m.body, m.sender_role, m.created_at, a.admin_name
        FROM request_messages m LEFT JOIN admin_account a ON a.id = m.sender_admin_id
        WHERE m.request_id = ?' . $condition . ' ORDER BY m.id ' . ($ascending ? 'ASC' : 'DESC') . ' LIMIT 101');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > 100;
    if ($hasMore) array_pop($rows);
    if (!$ascending) $rows = array_reverse($rows);
    $attachments = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $stmt = $pdo->prepare('SELECT * FROM request_attachments WHERE request_id = ? AND message_id IN (' .
            implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $stmt->execute(array_merge([$requestId], $ids));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
            $attachments[$file['message_id']][] = requestPublicAttachment($file);
        }
    }
    $messages = [];
    foreach ($rows as $row) {
        $messages[] = ['id' => (int) $row['id'], 'body' => $row['body'],
            'sender_role' => $row['sender_role'],
            'sender_name' => $row['sender_role'] === 'admin' ? ($row['admin_name'] ?: 'Administrator') : $record['student_name'],
            'created_at' => requestTimestamp($row['created_at']), 'attachments' => $attachments[$row['id']] ?? []];
    }
    requestJson(200, ['messages' => $messages, 'has_more' => $hasMore, 'request' => $record, 'csrf' => $requestCsrf, 'role' => $requestActor['role']]);
} catch (Throwable $error) {
    requestException($error, $pdo, $moved);
}
