<?php
// Shared routing policy. Existing staff accounts remain the source of role/active status.
function requestOffices(): array
{
    return ['Admin', 'Registrar', 'OSA'];
}

function requestDefaultOffice(string $type): string
{
    return match ($type) {
        'Grade Record Request', 'Certificate of Enrollment',
        'Certificate of Candidacy', 'Honorable Dismissal' => 'Registrar',
        'Good Moral Certificate', 'Student ID Replacement' => 'OSA',
        default => 'Admin',
    };
}

function requestCanForward(array $actor): bool
{
    return $actor['role'] === 'admin' && ($actor['office'] ?? null) === 'Admin';
}

// Internal aliases only. All identity/office values remain bound parameters.
function requestVisibility(array $actor, string $alias = ''): array
{
    $prefix = $alias === '' ? '' : $alias . '.';
    if ($actor['role'] === 'student') return [$prefix . 'student_id = ?', [$actor['id']]];
    if (requestCanForward($actor)) return ['1 = 1', []];
    if (in_array($actor['office'] ?? null, ['Registrar', 'OSA'], true)) {
        return [$prefix . 'assigned_office = ?', [$actor['office']]];
    }
    return ['1 = 0', []];
}

function requestOfficeAvailability(PDO $pdo): array
{
    $counts = ['Admin' => 0, 'Registrar' => 0, 'OSA' => 0];
    $statement = $pdo->prepare('SELECT role, COUNT(*) AS amount FROM admin_account
        WHERE is_active = 1 AND role IN (?, ?, ?) GROUP BY role');
    $statement->execute(['Administrator', 'Registrar', 'OSA']);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $office = $row['role'] === 'Administrator' ? 'Admin' : $row['role'];
        $counts[$office] = (int) $row['amount'];
    }
    return $counts;
}

function requestRoutingMetadata(PDO $pdo, array $actor): array
{
    $data = ['staff_office' => $actor['office'] ?? null, 'can_forward' => requestCanForward($actor)];
    if (!$data['can_forward']) return $data;
    $data['office_availability'] = requestOfficeAvailability($pdo);
    $data['routing_warnings'] = [];
    foreach ($data['office_availability'] as $office => $amount) {
        if ($amount === 0) $data['routing_warnings'][] =
            'No active ' . $office . ' account is available. Requests assigned to ' . $office
            . ' remain saved; review office accounts or forward requests to another office.';
    }
    return $data;
}

function requestAssignmentHistory(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare('SELECT h.id, h.previous_office, h.new_office,
        h.reassigned_by_admin_id, a.admin_name AS reassigned_by, h.created_at
        FROM request_assignment_history h JOIN admin_account a ON a.id = h.reassigned_by_admin_id
        WHERE h.request_id = ? ORDER BY h.id');
    $statement->execute([$id]);
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'], 'previous_office' => $row['previous_office'],
        'new_office' => $row['new_office'], 'reassigned_by_admin_id' => (int) $row['reassigned_by_admin_id'],
        'reassigned_by' => $row['reassigned_by'], 'created_at' => requestTimestamp($row['created_at']),
    ], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function requestWithHistory(PDO $pdo, array $row): array
{
    $record = requestPublic($row);
    $record['assignment_history'] = requestAssignmentHistory($pdo, $record['id']);
    return $record;
}
