-- Run in the existing kns_portal database after the student-account migrations.
-- No sample requests, students, messages, or status changes are inserted.
CREATE TABLE IF NOT EXISTS requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(40) NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    request_type VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(30) NOT NULL,
    purpose TEXT NOT NULL,
    status ENUM('Pending','Processing','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    submission_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_reference (reference_no),
    UNIQUE KEY uq_request_submission (student_id, submission_token),
    KEY ix_request_student_created (student_id, id),
    CONSTRAINT fk_request_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    sender_role ENUM('admin','student') NOT NULL,
    sender_admin_id TINYINT UNSIGNED NULL,
    sender_student_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    client_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_message_token (request_id, client_token),
    UNIQUE KEY uq_request_message_identity (request_id, id),
    CONSTRAINT fk_message_request FOREIGN KEY (request_id) REFERENCES requests (id),
    CONSTRAINT fk_message_admin FOREIGN KEY (sender_admin_id) REFERENCES admin_account (id),
    CONSTRAINT fk_message_student FOREIGN KEY (sender_student_id) REFERENCES students (id),
    CONSTRAINT ck_message_sender CHECK (
        (sender_role = 'admin' AND sender_admin_id IS NOT NULL AND sender_student_id IS NULL)
        OR (sender_role = 'student' AND sender_student_id IS NOT NULL AND sender_admin_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- message_id IS NULL identifies supporting files submitted with the request.
CREATE TABLE IF NOT EXISTS request_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    preview_mime VARCHAR(20) NULL,
    byte_size INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_request_storage_key (storage_key),
    KEY ix_request_attachment_message (request_id, message_id),
    CONSTRAINT fk_attachment_request FOREIGN KEY (request_id) REFERENCES requests (id),
    CONSTRAINT fk_attachment_message FOREIGN KEY (request_id, message_id)
        REFERENCES request_messages (request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
