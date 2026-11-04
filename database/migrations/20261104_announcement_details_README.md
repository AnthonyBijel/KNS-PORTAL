# Announcement title and Highlights

Import `20261104_announcement_details.sql` into the existing `kns_portal` database
before using the updated admin publisher. This follows the project's SQL migration
workflow and adds nullable columns only. Existing posts, media, IDs, and timestamps
are preserved. Older posts display their category as a fallback title and omit the
Highlights strip until Highlights are supplied; descriptions remain searchable.

The existing `admin/create_post.php` route saves the new fields with the existing
PDO connection and announcement uploads directory. It accepts authenticated,
CSRF-protected submissions issued by `admin/overview.php`. Submission tokens make
repeated requests from the same form return the existing saved post.

The overview loads all real posts and filters them locally by the combination of
search text (title, Highlights, description) and category. Legacy `Events` posts
are included in the `Event` filter without rewriting historical records. The
existing Share action and public announcement URLs remain in use.

The banner SVG uses a viewport over the school entrance in the supplied reference
image. The original image is retained in `asset/overview/welcome-reference.png`.
All new CSS is scoped under `.kns-overview`; the dashboard shell is unchanged.
