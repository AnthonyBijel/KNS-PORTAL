-- Prerequisite: 20261105_academic_term_management.sql.
-- Additive MariaDB migration. Safe to rerun; no account/status/history backfill.
ALTER TABLE student_enrollments
    ADD COLUMN IF NOT EXISTS activation_authorized TINYINT(1) NOT NULL DEFAULT 0;

-- Existing snapshots remain history, not evidence of an authorized new import.
-- Only the Admin/Registrar Excel workflow can approve activation after validation.
