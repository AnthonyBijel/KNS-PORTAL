-- MariaDB 10.4 / XAMPP. Run in the existing kns_portal database.
-- No account records, credentials, request statuses, messages, or files are changed.
ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS assigned_office ENUM('Admin','Registrar','OSA') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS assignment_revision INT UNSIGNED NOT NULL DEFAULT 0;

-- Backfill only unassigned requests. Rerunning never overwrites a reassignment.
UPDATE requests
SET assigned_office = CASE
    WHEN request_type IN ('Grade Record Request','Certificate of Enrollment',
        'Certificate of Candidacy','Honorable Dismissal') THEN 'Registrar'
    WHEN request_type IN ('Good Moral Certificate','Student ID Replacement') THEN 'OSA'
    ELSE 'Admin'
END
WHERE assigned_office IS NULL;

ALTER TABLE requests
    MODIFY COLUMN assigned_office ENUM('Admin','Registrar','OSA') NOT NULL DEFAULT 'Admin';
CREATE INDEX IF NOT EXISTS ix_request_office_status
    ON requests (assigned_office, status, id);

CREATE TABLE IF NOT EXISTS request_assignment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    previous_office ENUM('Admin','Registrar','OSA') NOT NULL,
    new_office ENUM('Admin','Registrar','OSA') NOT NULL,
    reassigned_by_admin_id TINYINT UNSIGNED NOT NULL,
    client_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_assignment_token (request_id, client_token),
    CONSTRAINT fk_assignment_request FOREIGN KEY (request_id) REFERENCES requests (id),
    CONSTRAINT fk_assignment_admin FOREIGN KEY (reassigned_by_admin_id) REFERENCES admin_account (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
