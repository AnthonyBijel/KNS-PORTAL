-- Optional fields preserve existing announcements without inventing content.
-- MariaDB IF NOT EXISTS makes this safe to reapply on this XAMPP installation.
ALTER TABLE announcements
    ADD COLUMN IF NOT EXISTS title VARCHAR(200) NULL DEFAULT NULL AFTER announcement_type,
    ADD COLUMN IF NOT EXISTS highlights TEXT NULL DEFAULT NULL AFTER title;
