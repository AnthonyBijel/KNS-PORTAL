# Announcement duration

Import `20261104_announcement_expiration.sql` into the existing `kns_portal`
database before using post durations. The migration is additive and repeatable
on the project's MariaDB/XAMPP installation. Existing posts keep NULL expiration.

Create Post offers No expiration, 1 day, 3 days, 7 days, 30 days, or a custom
future date/time. Presets start when publishing. Custom times use Asia/Manila;
all expiration values are stored as UTC DATETIME and compared with UTC_TIMESTAMP().
This works with both local-time feed connections and UTC notification connections.

Expired posts are excluded from the admin/student feed, active totals, student
announcement notifications, and public share pages. Open feeds hide expired cards
automatically. Database records and uploaded attachments are retained; this feature
does not permanently delete school content. No scheduled task is required.

Validation: `php tests/announcement_expiration.php` uses a temporary table only.
