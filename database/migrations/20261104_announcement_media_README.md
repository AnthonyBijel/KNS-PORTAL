# Multiple announcement images

Import 20261104_announcement_media.sql into the existing kns_portal database before
using the updated publisher. It adds one nullable column and is safe to reapply on
MariaDB. Existing announcements are preserved.

Posts accept up to 10 JPG, PNG, GIF, or WEBP images, or one MP4/WEBM video.
Attachments must total at most 20 MB and stay within PHP upload limits.
The picker adds images to the current selection, shows compact previews, and
allows individual removal. A two-column gallery stays beside the post text and
shows at most four thumbnails, without scrolling. If more images are attached,
the fourth thumbnail has a blurred background and a +N counter. Selecting it opens
the first hidden image in a keyboard-accessible viewer with Previous/Next controls.
All images remain available, and Open original shows the full-resolution file.

The media_attachments column stores an ordered JSON array of {path, type} objects.
The legacy media_path and media_type columns retain the first attachment.
Admin, student, shared pages, and share previews use the same attachment reader.
Deletion checks both storage formats before removing any unshared local file.
Failed publication removes files moved by that attempt.

Run php tests/announcement_media.php to check upload normalization, legacy
fallback, media safety, and database storage/references. Database checks use only
a temporary table, leaving stored school posts unchanged.
