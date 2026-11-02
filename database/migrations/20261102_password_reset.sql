-- Run once in the existing kns_portal database. Existing reset records are preserved.
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    channel ENUM('email', 'sms') NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_reset_student_created (student_id, created_at),
    KEY ix_reset_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_rate_limits (
    scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    hits SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (scope_hash),
    KEY ix_reset_rate_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;