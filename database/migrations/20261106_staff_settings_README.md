# Staff profile settings

Applies to School Administrator (Administrator), OSA Office (OSA), and Registrar Office (Registrar). Each uses its existing admin_account.id and existing staff permissions.

## Installation

Apply 20261106_staff_settings.sql to the existing database. It is safe to rerun and adds three tables without replacing account or profile data:

- staff_account_settings: notification flags, theme/language, alternate contact, and password-attempt throttling. One row per staff account.
- staff_email_changes: a hashed challenge for the signed-in account's pending email.
- staff_account_activity: private account-change notifications.

The migration extends admin_notification_reads.source_type for announcements and account activity. Existing receipts remain intact. New staff accounts receive their default preference row on first use.

## Behavior

The gear sits within the existing profile card. The seven menu items open compact dialog states, with no Sign out item.

- The profile information card is read-only and has no Reset/Save actions. Its legacy POST endpoint rejects updates (405); account edits happen in Settings. The large profile photo is display-only; photo changes use Profile Settings.
- Profile Settings saves the signed-in user's name. The Administrator can also edit their school name, matching the previous permission. School is read-only for OSA/Registrar; role/office is always read-only. Photos reuse php/admin/upload_profile_photo.php and its existing image validation, size limits, random filenames, and ownership checks.
- Notifications saves all four flags. Announcements, student requests/messages, and account activity filter the existing staff feed. Announcements start from the preference record's creation and must be unexpired. Request office scoping and individual read receipts remain enforced. Activity belongs only to its account.
- Email notifications controls optional account-change messages from this settings workflow. Requested verification codes are sent regardless of that optional flag. Optional delivery failures show a warning after the actual save, rather than claiming email was sent.
- Security verifies the current password and reuses the existing password policy and PASSWORD_DEFAULT hashing. New passwords require at least 12 characters, at most 72 bytes, and matching confirmation. Five wrong current passwords lock this action for 15 minutes. Successful changes clear pending email challenges and regenerate the current session ID.
- Appearance persists Light/Dark for the entire signed-in staff portal: sidebar, header, Overview, Results where permitted, Requests/Messenger, profile, and dialogs. admin/dashboard.php bootstraps the account preference on the HTML root before paint; staff-portal.css scopes the palette to that staff shell. Switching back to Light restores the existing palette and layout.
- Language persists English/Filipino across the staff portal. staff-translations.js defines interface strings; staff-portal.js applies them to navigation, page labels, filters, messages, accessible labels, and dynamically inserted dialogs. Authored posts/messages, names, filenames, student records, and input values are excluded. Original interface strings are retained per DOM node so English can be restored without a reload. Option values remain stable for filters/submissions. There was no existing translation system.
- Change Email reuses knsDeliverMessage, PHPMailer, and config/password-reset.php private/environment configuration. No new mail credentials are introduced. The current email stays unchanged until its new address is verified. Six-digit codes expire in 10 minutes, allow five attempts, and can be resent after 60 seconds. Duplicate emails, expired/replayed codes, and unavailable delivery are rejected. The legacy profile POST cannot bypass verification; its email input is read-only.
- Contact Details saves admin_account.school_contact, admin_account.school_address, and the account's alternate_contact setting. Existing office phone-number requirements are preserved.

All settings writes require CSRF. Account IDs and role fields supplied through URL/body parameters are rejected. School-name edits require the signed-in Administrator and the Profile Settings action; office accounts cannot change it. Identity comes from the session and the current active database account. Saves use prepared statements and account locks. Duplicate UI submissions are blocked during saves/uploads. PHP escapes displayed text; JavaScript uses textContent. Passwords and codes are never returned by the settings API.

## Verification

Run:

    C:\xampp\php\php.exe tests/staff_settings.php --browser

Tests use a disposable database and temporary web root. Mail is intercepted in service tests and disabled in HTTP/browser fixtures, so no real messages are sent. Coverage includes all three roles and seven panels, role immutability, duplicate submissions, existing photo uploads, verified email, persisted appearance/language, surrounding layout geometry, full-portal appearance/language across all three roles, filter values, read-only profile details, mobile sizing, keyboard navigation, Escape, close buttons, and outside clicks.

No additional migration is required for the full-portal follow-up; school name/address reuse the existing admin_account fields.
