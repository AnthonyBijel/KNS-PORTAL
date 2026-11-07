# Student account settings

Apply 20261107_student_settings.sql to the portal database. It creates student settings,
email-verification challenges, and account activity without changing existing student
records or credentials. Reapplying it preserves saved preferences.

Student Information now has a Settings gear with Profile Settings, Notifications,
Security, Appearance, Language, Change Email, and Contact Details. Profile names,
student IDs, academic details, and school identity remain read-only; photos use the
existing student upload endpoint.

Contact Details saves students.contact_number, students.address, and a separate
alternate contact. Password changes require the current password, matching new
passwords of 12 characters to 72 bytes, and an active student session. Five incorrect
current-password attempts lock password changes for 15 minutes. Changes invalidate
old password-recovery tokens.

New email addresses use the existing configured mail provider. They remain pending
until verified with a six-digit code (10 minutes, five attempts, 60-second resend
cooldown). Unavailable delivery leaves the existing email unchanged. No mail settings
or credentials are introduced.

Notification preferences filter the student's announcement, request/message, and
account-activity feed. Email preferences govern optional account-change notices.
Requested verification codes remain available regardless of notification preferences.

Appearance and language persist per student and apply across the signed-in portal.
Student-authored data, posts, messages, and academic values are excluded from interface
translation. Settings remain scoped by the existing per-tab session cookie.

Run php tests/student_settings.php for isolated schema, validation, authorization,
email, login, and notification checks. Add --browser for browser UI checks. Tests use
a disposable database and delivery fixtures and never send real messages.
