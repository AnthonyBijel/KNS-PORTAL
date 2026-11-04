<?php
require __DIR__ . '/../request/messenger-common.php';

if ($requestActor['role'] !== 'student') requestFail(403, 'Student notifications are only available to the signed-in student.');
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    requestFail(405, 'Use GET to read notifications or POST to mark them read.');
}

// A live feed avoids a separate copy of every school announcement for every account.
// Both UNION branches use one collation because the existing source tables differ.
function notificationFeed(): string
{
    return "SELECT 'announcement' COLLATE utf8mb4_unicode_ci AS source_type,
        a.id AS source_id, NULL AS request_id, a.id AS announcement_id,
        NULL AS reference_no, NULL AS request_type,
        a.announcement_type COLLATE utf8mb4_unicode_ci AS category,
        a.description COLLATE utf8mb4_unicode_ci AS description,
        NULL AS event_status, NULL AS sender_name, a.created_at
        FROM announcements a WHERE (a.expires_at IS NULL OR a.expires_at > UTC_TIMESTAMP())
        UNION ALL
        SELECT m.event_kind COLLATE utf8mb4_unicode_ci, m.id, r.id, NULL,
        r.reference_no, r.request_type, NULL, m.body, m.event_status,
        ac.admin_name, m.created_at
        FROM request_messages m
        JOIN requests r ON r.id = m.request_id
        JOIN admin_account ac ON ac.id = m.sender_admin_id
        WHERE r.student_id = ? AND m.sender_role = 'admin'";
}

function notificationPublic(array $row): array
{
    $type = $row['source_type'];
    $description = preg_replace('/\s+/u', ' ', trim($row['description'] ?? ''));
    $description = mb_strlen($description) > 160 ? mb_substr($description, 0, 157) . '…' : $description;
    $status = $row['event_status'] ?? null;
    if ($type === 'announcement') {
        $title = 'New announcement';
        $context = $row['category'];
    } elseif ($type === 'request_status') {
        $title = match ($status) {
            'Approved' => 'Request approved',
            'Rejected' => 'Request rejected',
            'Processing' => 'Request is being processed',
            default => 'Request status updated'
        };
        $description = 'Your ' . $row['request_type'] . ' has been ' .
            match ($status) {
                'Approved' => 'approved.',
                'Rejected' => 'rejected.',
                'Processing' => 'marked as processing.',
                default => 'updated.'
            };
        $context = $row['reference_no'];
    } else {
        $title = 'New message';
        $sender = $row['sender_name'] ?: 'School Administrator';
        $description = $description !== '' ? $sender . ': ' . $description : $sender . ' sent you an attachment.';
        $context = $row['request_type'] . ' · ' . $row['reference_no'];
    }
    return ['key' => $type . ':' . $row['source_id'], 'type' => $type,
        'source_id' => (int) $row['source_id'],
        'request_id' => $row['request_id'] === null ? null : (int) $row['request_id'],
        'announcement_id' => $row['announcement_id'] === null ? null : (int) $row['announcement_id'],
        'title' => $title, 'description' => $description, 'context' => $context,
        'status' => $status, 'read' => $row['read_at'] !== null,
        'created_at' => requestTimestamp($row['created_at'])];
}

function notificationCursor(array $row): string
{
    return base64_encode(json_encode(['at' => $row['created_at'],
        'type' => $row['source_type'], 'id' => (int) $row['source_id']]));
}

$studentId = $requestActor['id'];
try {
    $feed = notificationFeed();
    if ($method === 'POST') {
        $action = requestText($_POST, 'action', 30, true);
        $pdo->beginTransaction();
        if ($action === 'read') {
            $type = requestText($_POST, 'type', 30, true);
            if (!in_array($type, ['announcement', 'message', 'request_status'], true)) {
                throw new InvalidArgumentException('Invalid notification type.');
            }
            $id = requestInteger($_POST['source_id'] ?? null);
            $stmt = $pdo->prepare('SELECT e.*, NULL AS read_at FROM (' . $feed .
                ') e WHERE e.source_type = ? AND e.source_id = ? LIMIT 1');
            $stmt->execute([$studentId, $type, $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                requestFail(404, 'This notification is no longer available to your account.');
            }
            $stmt = $pdo->prepare('INSERT INTO student_notification_reads
                (student_id, source_type, source_id) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE read_at = student_notification_reads.read_at');
            $stmt->execute([$studentId, $type, $id]);
            $row['read_at'] = gmdate('Y-m-d H:i:s');
            $pdo->commit();
            requestJson(200, ['notification' => notificationPublic($row)]);
        }
        if ($action === 'read_all') {
            $stmt = $pdo->prepare('INSERT INTO student_notification_reads (student_id, source_type, source_id)
                SELECT ?, e.source_type, e.source_id FROM (' . $feed . ') e
                WHERE 1 = 1
                ON DUPLICATE KEY UPDATE read_at = student_notification_reads.read_at');
            $stmt->execute([$studentId, $studentId]);
        } elseif ($action === 'read_request') {
            $requestId = requestInteger($_POST['request_id'] ?? null);
            $lastMessageId = requestInteger($_POST['last_message_id'] ?? null);
            requestRecord($pdo, $requestId, $requestActor);
            $stmt = $pdo->prepare("INSERT INTO student_notification_reads (student_id, source_type, source_id)
                SELECT ?, m.event_kind, m.id FROM request_messages m
                JOIN requests r ON r.id = m.request_id
                WHERE r.student_id = ? AND r.id = ? AND m.sender_role = 'admin' AND m.id <= ?
                ON DUPLICATE KEY UPDATE read_at = student_notification_reads.read_at");
            $stmt->execute([$studentId, $studentId, $requestId, $lastMessageId]);
        } else {
            throw new InvalidArgumentException('Choose a valid notification action.');
        }
        $pdo->commit();
        requestJson(200, ['message' => 'Notifications marked as read.']);
    }

    $filter = requestText($_GET, 'filter', 10);
    if (!in_array($filter, ['', 'all', 'unread'], true)) throw new InvalidArgumentException('Invalid notification filter.');
    $where = $filter === 'unread' ? ['n.source_id IS NULL'] : [];
    $params = [$studentId, $studentId];
    $cursor = requestText($_GET, 'before', 512);
    if ($cursor !== '') {
        $decoded = base64_decode($cursor, true);
        $position = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($position) || !is_string($position['at'] ?? null)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $position['at'])
            || !in_array($position['type'] ?? null, ['announcement', 'message', 'request_status'], true)) {
            throw new InvalidArgumentException('Invalid notification page.');
        }
        $id = requestInteger($position['id'] ?? null);
        $where[] = '(e.created_at < ? OR (e.created_at = ? AND
            (e.source_type < ? OR (e.source_type = ? AND e.source_id < ?))))';
        array_push($params, $position['at'], $position['at'], $position['type'], $position['type'], $id);
    }
    $pdo->beginTransaction();
    $join = ' FROM (' . $feed . ') e LEFT JOIN student_notification_reads n
        ON n.student_id = ? AND n.source_type = e.source_type AND n.source_id = e.source_id';
    $counts = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(n.source_id IS NULL), 0) AS unread' . $join);
    $counts->execute([$studentId, $studentId]);
    $totals = $counts->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT e.*, n.read_at' . $join .
        ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
        ' ORDER BY e.created_at DESC, e.source_type DESC, e.source_id DESC LIMIT 21');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 20;
    if ($more) array_pop($rows);
    $next = $more && $rows ? notificationCursor($rows[count($rows) - 1]) : null;
    $pdo->commit();
    requestJson(200, ['notifications' => array_map('notificationPublic', $rows),
        'unread_count' => (int) $totals['unread'], 'total' => (int) $totals['total'],
        'next_cursor' => $next, 'csrf' => $requestCsrf]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof PDOException && in_array($error->getCode(), ['42S02', '42S22'], true)) {
        requestFail(503, 'Notifications are not installed yet. Import 20261101_student_notifications.sql into the kns_portal database, then reload this page.');
    }
    requestException($error, $pdo);
}
