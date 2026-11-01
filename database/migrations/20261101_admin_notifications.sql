-- Run in the existing kns_portal database after the Request Messenger migration.
-- Admin read receipts are separate from student read receipts.
-- No request, message, attachment, or status is changed.
CREATE TABLE IF NOT EXISTS admin_notification_reads (
    admin_id TINYINT UNSIGNED NOT NULL,
    source_type ENUM('request','message') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id, source_type, source_id),
    CONSTRAINT fk_admin_notification_reader
        FOREIGN KEY (admin_id) REFERENCES admin_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
