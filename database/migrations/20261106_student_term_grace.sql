-- Prerequisites: academic term management, student term activation, password reset/account setup.
-- MariaDB additive migration. Safe to rerun. No account/status/date backfill or deletions.
-- All DATETIME values below are UTC; calendar calculations use configured Asia/Manila.
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS term_inactivated_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS term_expires_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS term_inactive_term_id INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS student_term_emails (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    term_id INT UNSIGNED NOT NULL,
    inactivated_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    delivery_status ENUM('queued','sending','sent','failed','uncertain','cancelled') NOT NULL DEFAULT 'queued',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    claimed_at DATETIME NULL,
    sent_at DATETIME NULL,
    failure_code VARCHAR(40) NULL,
    photo_cleanup_path VARCHAR(255) NULL,
    UNIQUE KEY uq_student_term_email (student_id, term_id),
    KEY ix_term_email_delivery (delivery_status, next_attempt_at),
    CONSTRAINT fk_term_email_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
    CONSTRAINT fk_term_email_term FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
