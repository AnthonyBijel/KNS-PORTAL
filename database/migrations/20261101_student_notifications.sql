-- Run once in the existing kns_portal database, after 20261101_request_messenger.sql.
-- Preserve all requests, messages, attachments, and statuses.
ALTER TABLE request_messages
    ADD COLUMN event_kind ENUM('message','request_status') NOT NULL DEFAULT 'message' AFTER body,
    ADD COLUMN event_status ENUM('Processing','Approved','Rejected') NULL DEFAULT NULL AFTER event_kind;

-- Identify the status responses created by the existing admin action endpoint.
-- Match the real reference as well as the exact status line; ordinary messages stay messages.
UPDATE request_messages m
JOIN requests r ON r.id = m.request_id
SET m.event_kind = 'request_status',
    m.event_status = SUBSTRING(SUBSTRING_INDEX(m.body, CHAR(10), 1), 17)
WHERE m.sender_role = 'admin'
  AND SUBSTRING_INDEX(m.body, CHAR(10), 1) IN
      ('Request status: Processing', 'Request status: Approved', 'Request status: Rejected')
  AND SUBSTRING_INDEX(SUBSTRING_INDEX(m.body, CHAR(10), 2), CHAR(10), -1)
      = CONCAT('Reference: ', r.reference_no);

CREATE TABLE IF NOT EXISTS student_notification_reads (
    student_id INT UNSIGNED NOT NULL,
    source_type ENUM('announcement','message','request_status') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, source_type, source_id),
    CONSTRAINT fk_notification_reader FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
