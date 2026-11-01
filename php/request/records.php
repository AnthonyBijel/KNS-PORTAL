<?php
require __DIR__ . '/messenger-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    requestFail(405, 'Use GET to read requests.');
}
try {
    if (isset($_GET['id'])) {
        $record = requestPublic(requestRecord($pdo, requestInteger($_GET['id']), $requestActor));
        $stmt = $pdo->prepare('SELECT * FROM request_attachments WHERE request_id = ? AND message_id IS NULL ORDER BY id');
        $stmt->execute([$record['id']]);
        $record['attachments'] = array_map('requestPublicAttachment', $stmt->fetchAll(PDO::FETCH_ASSOC));
        requestJson(200, ['request' => $record, 'csrf' => $requestCsrf, 'role' => $requestActor['role']]);
    }

    $where = [];
    $params = [];
    if ($requestActor['role'] === 'student') {
        $where[] = 'r.student_id = ?';
        $params[] = $requestActor['id'];
    }
    $q = requestText($_GET, 'q', 200);
    if ($q !== '') {
        $where[] = "(r.reference_no LIKE ? OR r.request_type LIKE ? OR CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) LIKE ?)";
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    foreach (['status', 'academic_year', 'semester', 'request_type'] as $field) {
        $value = requestText($_GET, $field, 100);
        if ($value !== '') {
            $where[] = 'r.' . $field . ' = ?';
            $params[] = $value;
        }
    }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $join = ' FROM requests r JOIN students s ON s.id = r.student_id';
    $count = $pdo->prepare('SELECT COUNT(*)' . $join . $clause);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $perPage = (int) ($_GET['per_page'] ?? 10);
    if (!in_array($perPage, [10, 25, 50], true)) $perPage = 10;
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare('SELECT r.*, s.student_number, s.first_name, s.middle_name, s.last_name,
        s.suffix, s.course, s.year_level, s.section, s.email' . $join . $clause .
        ' ORDER BY r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $records = array_map('requestPublic', $stmt->fetchAll(PDO::FETCH_ASSOC));
    $ownerClause = $requestActor['role'] === 'student' ? ' WHERE student_id = ?' : '';
    $ownerParams = $requestActor['role'] === 'student' ? [$requestActor['id']] : [];
    $stats = $pdo->prepare('SELECT status, COUNT(*) AS amount FROM requests' . $ownerClause . ' GROUP BY status');
    $stats->execute($ownerParams);
    $summary = ['Total' => 0, 'Pending' => 0, 'Processing' => 0, 'Approved' => 0, 'Rejected' => 0];
    foreach ($stats->fetchAll(PDO::FETCH_ASSOC) as $stat) {
        $summary[$stat['status']] = (int) $stat['amount'];
        $summary['Total'] += (int) $stat['amount'];
    }
    $years = $pdo->prepare('SELECT DISTINCT academic_year FROM requests' . $ownerClause . ' ORDER BY academic_year DESC');
    $years->execute($ownerParams);
    $profile = null;
    if ($requestActor['role'] === 'student') {
        $stmt = $pdo->prepare('SELECT academic_year, semester FROM students WHERE id = ?');
        $stmt->execute([$requestActor['id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    requestJson(200, ['requests' => $records, 'page' => $page, 'pages' => $pages,
        'total' => $total, 'per_page' => $perPage, 'summary' => $summary,
        'academic_years' => $years->fetchAll(PDO::FETCH_COLUMN), 'request_types' => requestTypes(),
        'profile' => $profile, 'csrf' => $requestCsrf, 'role' => $requestActor['role']]);
} catch (Throwable $error) {
    requestException($error, $pdo);
}
