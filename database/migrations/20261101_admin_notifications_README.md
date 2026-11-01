# Admin notifications

In XAMPP, open http://localhost/phpmyadmin, select the existing kns_portal
database, choose Import, and import 20261101_admin_notifications.sql.
The Request Messenger tables must already exist. Then reload the admin dashboard.

This adds only admin_notification_reads, a per-administrator read-receipt table.
It does not modify existing requests, conversations, attachments, statuses,
student notifications, or student read receipts.

The admin uses the same notification panel JavaScript and CSS as the student:
green header, unread count on the bell, All/Unread tabs, Mark all as read,
timestamps, unread dots, and paginated history. Visible dashboard tabs refresh
every five seconds. The admin endpoint returns only:
- Real requests submitted by students.
- Messages and file/photo messages authored by the owner of each request.

Announcements and administrator-authored messages/status responses are excluded.
Existing requests and student messages initially appear unread until acknowledged.

Clicking a request notification opens its actual Request Details card with chat
hidden. Clicking a student-message notification opens the same card and Messenger
for that request. Navigation works even if the request is outside the current
table page or filter. Opening Request Details acknowledges the request only;
opening its conversation acknowledges displayed student messages as well.

All data and read actions require an existing administrator session. Read actions
also require the existing CSRF token. Read receipts use the actual signed-in
administrator ID and remain separate from each student's read state.

No migration or tests were run during implementation. No commit or push was made.
