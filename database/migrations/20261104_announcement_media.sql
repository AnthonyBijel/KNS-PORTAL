-- Preserve existing attachments and historical posts.
-- Store ordered attachments without adding feed queries.
ALTER TABLE announcements
    ADD COLUMN IF NOT EXISTS media_attachments LONGTEXT NULL DEFAULT NULL AFTER media_type;
