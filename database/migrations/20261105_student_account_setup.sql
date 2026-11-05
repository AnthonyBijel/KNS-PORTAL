-- Apply to the EXISTING kns_portal database. MariaDB supports ADD COLUMN IF NOT EXISTS.
-- Existing accounts retain their passwords, photos, related data, and normal login.
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS requires_password_change TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS temporary_password_expires_at DATETIME NULL DEFAULT NULL;
