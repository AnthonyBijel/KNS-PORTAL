-- Expiry is UTC DATETIME, not affected by connection time_zone settings.
-- NULL preserves the visibility of every existing post.
ALTER TABLE announcements
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL DEFAULT NULL AFTER media_type,
    ADD INDEX IF NOT EXISTS idx_announcements_expires_at (expires_at);
