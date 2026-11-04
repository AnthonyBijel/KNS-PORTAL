<?php
require __DIR__ . '/messenger-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    requestFail(405, 'Use the request submission form.');
}
if ($requestActor['role'] !== 'student') requestFail(403, 'Only students can submit requests.');
$moved = [];
try {
    $type = requestText($_POST, 'request_type', 100, true);
    if (!in_array($type, requestTypes(), true)) throw new InvalidArgumentException('Choose a request type.');
    $year = requestText($_POST, 'academic_year', 20, true);
    if (!preg_match('/^([0-9]{4}) - ([0-9]{4})$/D', $year, $match) || (int) $match[2] !== (int) $match[1] + 1) {
        throw new InvalidArgumentException('Choose a valid academic year.');
    }
    $semester = requestText($_POST, 'semester', 30, true);
    if (!in_array($semester, ['1st Semester', '2nd Semester', 'Summer'], true)) {
        throw new InvalidArgumentException('Choose a valid semester.');
    }
    $subject = requestText($_POST, 'subject', 255);
    $purpose = requestText($_POST, 'purpose', 5000, true);
    $nonce = requestToken($_POST, 'submission_token');
    $files = requestUploadFiles();
    $pdo->beginTransaction();
    // Serialize repeated submissions by the same student, including network retries.
    $stmt = $pdo->prepare('SELECT id FROM students WHERE id = ? FOR UPDATE');
    $stmt->execute([$requestActor['id']]);
    $stmt = $pdo->prepare('SELECT id FROM requests WHERE student_id = ? AND submission_token = ?');
    $stmt->execute([$requestActor['id'], $nonce]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $reference = 'REQ-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y') .
            '-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $pdo->prepare('INSERT INTO requests
            (reference_no, student_id, request_type, subject, academic_year, semester, purpose, submission_token, assigned_office)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$reference, $requestActor['id'], $type, $subject ?: null, $year, $semester, $purpose, $nonce, requestDefaultOffice($type)]);
        $id = (int) $pdo->lastInsertId();
        requestSaveAttachments($pdo, $id, null, $files, $moved);
    }
    $record = requestPublic(requestRecord($pdo, (int) $id, $requestActor));
    $pdo->commit();
    requestJson(201, ['request' => $record]);
} catch (Throwable $error) {
    requestException($error, $pdo, $moved);
}
