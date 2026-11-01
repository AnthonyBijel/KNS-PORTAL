# Student notifications

Run 20261101_student_notifications.sql once in the existing kns_portal database,
after the Request Messenger migration. In XAMPP: http://localhost/phpmyadmin >
kns_portal > Import > choose the SQL file. Reload the student dashboard afterward.

This adds per-student notification read receipts and explicit message/status-event
metadata. Existing admin status responses are recognized by their exact status
line and actual request reference. Requests and their statuses are not changed.

The bell displays a count of unread announcements, administrator messages, and
request-status responses. The green-and-white dropdown has All/Unread tabs,
Mark all as read, dated groups, unread indicators, and paginated notification
history. Notifications are loaded from real records; there are no sample rows.
Existing announcements and admin responses initially appear unread until opened
or marked read. Student-sent messages do not notify the same student.

Updates are polled every five seconds while the dashboard tab is visible.
Opening an announcement notification loads and highlights its actual post.
Opening a message/status notification loads the owning student's request
conversation even when that request is not in the current table page.
Opening a conversation in a visible tab also acknowledges admin messages through
the latest displayed message. Read state persists between logins and devices.

The endpoint requires the existing active student session and verifies request
ownership. All read-state changes require the existing CSRF token. School
announcements are visible to all students; request messages remain private to
their owning student. Approval/status decisions remain admin-only.

No migration or tests were run during this implementation. No browser push,
email notifications, or AI/bot integrations are added.
