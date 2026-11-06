<?php
require __DIR__ . '/../request/messenger-common.php';
require_once __DIR__ . '/staff-settings-common.php';

if ($requestActor['role'] !== 'admin') requestFail(403, 'Administrator notifications require an administrator session.');
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    requestFail(405, 'Use GET to read notifications or POST to mark them read.');
}

function adminNotificationFeed(bool $scoped): string
{
    // Only actual student requests and student-authored messages. No announcements
    // or administrator-authored messages/status responses enter this feed.
    $officeWhere = $scoped ? ' AND r.assigned_office = ?' : '';
    return "SELECT 'request' COLLATE utf8mb4_unicode_ci AS source_type,
        r.id AS source_id, r.id AS request_id, r.student_id,
        r.reference_no, r.request_type,
        CONCAT_WS(' ', s.first_name, NULLIF(s.middle_name, ''), s.last_name, NULLIF(s.suffix, '')) AS student_name,
        s.student_number, r.purpose AS description, r.created_at
        FROM requests r JOIN students s ON s.id = r.student_id WHERE 1 = 1" . $officeWhere . "
        UNION ALL
        SELECT 'message' COLLATE utf8mb4_unicode_ci, m.id, r.id, r.student_id,
        r.reference_no, r.request_type,
        CONCAT_WS(' ', s.first_name, NULLIF(s.middle_name, ''), s.last_name, NULLIF(s.suffix, '')),
        s.student_number, m.body, m.created_at
        FROM request_messages m JOIN requests r ON r.id = m.request_id
        JOIN students s ON s.id = r.student_id
        WHERE m.sender_role = 'student' AND m.sender_student_id = r.student_id" . $officeWhere;
}

function adminNotificationPublic(array $row): array
{
    if (in_array($row['source_type'], ['announcement','activity'], true)) {
        return ['key'=>$row['source_type'].':'.$row['source_id'], 'type'=>$row['source_type'],
            'source_id'=>(int)$row['source_id'], 'request_id'=>null, 'student_id'=>null, 'student_name'=>'',
            'title'=>$row['source_type']==='announcement' ? $row['student_name'] : 'Account activity',
            'description'=>mb_substr(preg_replace('/\s+/u',' ',trim($row['description'])),0,160),
            'context'=>$row['source_type']==='announcement' ? 'System announcements' : 'Your account',
            'read'=>$row['read_at']!==null, 'created_at'=>requestTimestamp($row['created_at'])];
    }
    $request = $row['source_type'] === 'request';
    $description = preg_replace('/\s+/u', ' ', trim($row['description'] ?? ''));
    $description = mb_strlen($description) > 160 ? mb_substr($description, 0, 157) . '…' : $description;
    if ($request) {
        $title = 'New student request';
        $description = $row['student_name'] . ' submitted a ' . $row['request_type'] . '.';
        $context = $row['reference_no'] . ' · ' . $row['student_number'];
    } else {
        $title = 'New student message';
        $description = $description !== '' ? $row['student_name'] . ': ' . $description
            : $row['student_name'] . ' sent you an attachment.';
        $context = $row['request_type'] . ' · ' . $row['reference_no'];
    }
    return ['key' => $row['source_type'] . ':' . $row['source_id'],
        'type' => $row['source_type'], 'source_id' => (int) $row['source_id'],
        'request_id' => (int) $row['request_id'], 'student_id' => (int) $row['student_id'],
        'student_name' => $row['student_name'], 'title' => $title,
        'description' => $description, 'context' => $context,
        'read' => $row['read_at'] !== null, 'created_at' => requestTimestamp($row['created_at'])];
}

function adminNotificationCursor(array $row): string
{
    return base64_encode(json_encode(['at' => $row['created_at'],
        'type' => $row['source_type'], 'id' => (int) $row['source_id']]));
}

$adminId = $requestActor['id'];
try {
    $scoped = !requestCanForward($requestActor);
    $feed = adminNotificationFeed($scoped);
    $feedParams = $scoped ? [$requestActor['office'], $requestActor['office']] : [];
    $preferences = knsStaffPreferences($pdo, $adminId);
    if ($preferences['installed']) {
        if (!$preferences['notify_requests']) $feed = 'SELECT * FROM (' . $feed . ') requests_disabled WHERE 1=0';
        if ($preferences['notify_announcements']) {
            $feed .= " UNION ALL SELECT 'announcement' COLLATE utf8mb4_unicode_ci, a.id, NULL, NULL, '', '',
                COALESCE(NULLIF(a.title,''),'System announcement') COLLATE utf8mb4_unicode_ci, '',
                a.description COLLATE utf8mb4_unicode_ci, a.created_at FROM announcements a
                WHERE a.created_at >= ? AND (a.expires_at IS NULL OR a.expires_at > UTC_TIMESTAMP())";
            $feedParams[] = $preferences['created_at'];
        }
        if ($preferences['notify_activity']) {
            $feed .= " UNION ALL SELECT 'activity' COLLATE utf8mb4_unicode_ci, a.id, NULL, NULL, '', '', '', '',
                a.description, a.created_at FROM staff_account_activity a WHERE a.admin_id = ?";
            $feedParams[] = $adminId;
        }
    }
    if ($method === 'POST') {
        $action = requestText($_POST, 'action', 30, true);
        $pdo->beginTransaction();
        if ($action === 'read') {
            $type = requestText($_POST, 'type', 20, true);
            if (!in_array($type, ['request', 'message', 'announcement', 'activity'], true)) throw new InvalidArgumentException('Invalid notification type.');
            $id = requestInteger($_POST['source_id'] ?? null);
            $stmt = $pdo->prepare('SELECT e.*, NULL AS read_at FROM (' . $feed .
                ') e WHERE e.source_type = ? AND e.source_id = ? LIMIT 1');
            $stmt->execute([...$feedParams, $type, $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                requestFail(404, 'This notification is no longer available.');
            }
            if (in_array($type, ['request','message'], true)) requestRecord($pdo, (int) $row['request_id'], $requestActor, true);
            $stmt = $pdo->prepare('INSERT INTO admin_notification_reads (admin_id, source_type, source_id)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE read_at = admin_notification_reads.read_at');
            $stmt->execute([$adminId, $type, $id]);
            $row['read_at'] = gmdate('Y-m-d H:i:s');
            $pdo->commit();
            requestJson(200, ['notification' => adminNotificationPublic($row)]);
        }
        if ($action === 'read_all') {
            $stmt = $pdo->prepare('INSERT INTO admin_notification_reads (admin_id, source_type, source_id)
                SELECT ?, e.source_type, e.source_id FROM (' . $feed . ') e WHERE 1 = 1
                ON DUPLICATE KEY UPDATE read_at = admin_notification_reads.read_at');
            $stmt->execute([$adminId, ...$feedParams]);
        } elseif ($action === 'read_request') {
            $requestId = requestInteger($_POST['request_id'] ?? null);
            $lastMessageId = isset($_POST['last_message_id']) ? requestInteger($_POST['last_message_id']) : null;
            requestRecord($pdo, $requestId, $requestActor, true);
            $stmt = $pdo->prepare("INSERT INTO admin_notification_reads (admin_id, source_type, source_id)
                VALUES (?, 'request', ?) ON DUPLICATE KEY UPDATE read_at = admin_notification_reads.read_at");
            $stmt->execute([$adminId, $requestId]);
            // Opening details acknowledges the request only. Student messages are
            // acknowledged only after the conversation has actually displayed them.
            if ($lastMessageId !== null) {
                $stmt = $pdo->prepare("INSERT INTO admin_notification_reads (admin_id, source_type, source_id)
                    SELECT ?, 'message', m.id FROM request_messages m
                    JOIN requests r ON r.id = m.request_id
                    WHERE m.request_id = ? AND m.sender_role = 'student'
                    AND m.sender_student_id = r.student_id AND m.id <= ?
                    ON DUPLICATE KEY UPDATE read_at = admin_notification_reads.read_at");
                $stmt->execute([$adminId, $requestId, $lastMessageId]);
            }
        } else {
            throw new InvalidArgumentException('Choose a valid notification action.');
        }
        $pdo->commit();
        requestJson(200, ['message' => 'Notifications marked as read.']);
    }

    $filter = requestText($_GET, 'filter', 10);
    if (!in_array($filter, ['', 'all', 'unread'], true)) throw new InvalidArgumentException('Invalid notification filter.');
    $where = $filter === 'unread' ? ['n.source_id IS NULL'] : [];
    $params = [...$feedParams, $adminId];
    $cursor = requestText($_GET, 'before', 512);
    if ($cursor !== '') {
        $decoded = base64_decode($cursor, true);
        $position = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($position) || !is_string($position['at'] ?? null)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $position['at'])
            || !in_array($position['type'] ?? null, ['request', 'message', 'announcement', 'activity'], true)) {
            throw new InvalidArgumentException('Invalid notification page.');
        }
        $id = requestInteger($position['id'] ?? null);
        $where[] = '(e.created_at < ? OR (e.created_at = ? AND
            (e.source_type < ? OR (e.source_type = ? AND e.source_id < ?))))';
        array_push($params, $position['at'], $position['at'], $position['type'], $position['type'], $id);
    }
    $pdo->beginTransaction();
    $join = ' FROM (' . $feed . ') e LEFT JOIN admin_notification_reads n
        ON n.admin_id = ? AND n.source_type = e.source_type AND n.source_id = e.source_id';
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(n.source_id IS NULL), 0) AS unread' . $join);
    $stmt->execute([...$feedParams, $adminId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT e.*, n.read_at' . $join .
        ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
        ' ORDER BY e.created_at DESC, e.source_type DESC, e.source_id DESC LIMIT 21');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 20;
    if ($more) array_pop($rows);
    $next = $more && $rows ? adminNotificationCursor($rows[count($rows) - 1]) : null;
    $pdo->commit();
    requestJson(200, ['notifications' => array_map('adminNotificationPublic', $rows),
        'unread_count' => (int) $counts['unread'], 'total' => (int) $counts['total'],
        'next_cursor' => $next, 'csrf' => $requestCsrf]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof PDOException && str_contains($error->getMessage(), 'assigned_office')) requestException($error, $pdo);
    if ($error instanceof PDOException && in_array($error->getCode(), ['42S02', '42S22'], true)) {
        requestFail(503, 'Admin notifications are not installed yet. Import 20261101_admin_notifications.sql into the kns_portal database, then reload this page.');
    }
    requestException($error, $pdo);
}
