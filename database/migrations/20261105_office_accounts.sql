-- MariaDB 10.4 / XAMPP: extend the existing staff identity table.
-- Existing account IDs, password hashes, profile values, and request FKs are preserved.
-- The original singleton CHECK must be removed to permit Registrar and OSA rows.
ALTER TABLE admin_account
    DROP CONSTRAINT IF EXISTS only_one_admin,
    MODIFY COLUMN id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Username already has a unique index. NULL legacy emails remain valid.
CREATE UNIQUE INDEX IF NOT EXISTS uq_admin_account_school_email
    ON admin_account (school_email);
