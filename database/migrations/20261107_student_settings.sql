-- Add student settings without changing student identities or credentials.
CREATE TABLE IF NOT EXISTS student_account_settings (
    student_id INT UNSIGNED NOT NULL PRIMARY KEY,
    notify_announcements TINYINT(1) NOT NULL DEFAULT 1,
    notify_requests TINYINT(1) NOT NULL DEFAULT 1,
    notify_activity TINYINT(1) NOT NULL DEFAULT 1,
    notify_email TINYINT(1) NOT NULL DEFAULT 1,
    theme ENUM('light','dark') NOT NULL DEFAULT 'light',
    language ENUM('en','fil') NOT NULL DEFAULT 'en',
    alternate_contact VARCHAR(30) NULL,
    auth_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    auth_locked_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_settings_account FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO student_account_settings (student_id) SELECT id FROM students;

CREATE TABLE IF NOT EXISTS student_email_changes (
    student_id INT UNSIGNED NOT NULL PRIMARY KEY,
    new_email VARCHAR(254) NOT NULL,
    previous_email VARCHAR(254) NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_student_email_account FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_account_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_student_activity_account (student_id, id),
    CONSTRAINT fk_student_activity_account FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_notification_reads
    MODIFY COLUMN source_type ENUM('announcement','message','request_status','activity') NOT NULL;
