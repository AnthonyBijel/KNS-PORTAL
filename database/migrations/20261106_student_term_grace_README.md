# Student term-end grace, expiry, and email notices

This extends the existing term-activation feature. Use this guide for current
access, retention, email, and scheduler behavior; it supersedes immediate blocking
at term end in earlier migration guides.

## Migration (run manually)

Back up the existing `kns_portal` database with phpMyAdmin Export. Select the
existing database, choose Import, and import `20261106_student_term_grace.sql`.
Prerequisites: the existing password-reset/student-account-setup migrations,
`20261105_academic_term_management.sql`, and `20261105_student_term_activation.sql`.

The additive, rerunnable migration adds four nullable lifecycle fields to students
and one outbox table. It does not backfill dates, change status, purge data, send
mail, or install a task. No application code runs migrations automatically.

Equivalent PowerShell command (after backup):

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p kns_portal --execute="SOURCE C:/xampp/htdocs/kns_portal/database/migrations/20261106_student_term_grace.sql"
```

Apply the migration before using the updated code. A missing schema fails account
checks rather than allowing access without expiry enforcement.

## Status and full grace access

Calendar calculations use the timezone in `config/academic-terms.php`, Asia/Manila.
Lifecycle DATETIME values are stored in UTC and displayed with Asia/Manila labels.
Active status lasts through the official end date. At 00:00 the next calendar day,
currently Active accounts become `status=Inactive, term_auto_inactive=1`. This
covers legacy accounts with no enrollment snapshot or no saved term link.

Inactivation is anchored to the term boundary, not the time a delayed task runs.
Expiry is three calendar months later, clamped to the last valid day of the target
month. For example, an end date of 2026-12-10 gives inactivation at 2026-12-11
00:00:00 and expiry at 2027-03-11 00:00:00 Asia/Manila. November 30 plus three
months becomes February 28 (February 29 in a leap year).

Term-inactive accounts keep full normal portal access strictly before expiry:
dashboard, announcements, profile/photo upload, academic results, notifications,
requests, messaging/attachments, password recovery, and password setup where
required. Every account-status check uses the same PHP/SQL access rules. A small
grace notice appears in the existing overview/profile and Admin status area.
Existing password-setup requirements still apply.

At the exact expiry instant, login and protected requests are blocked. Request-time
sync scrubs the expired account even if the scheduler was missed. Admin Students
List and overview reads also synchronize and exclude archived identities.

Existing term-inactive accounts without recorded dates are evaluated against the
saved elapsed calendar on the first sync. The migration is not a fresh three-month
extension: a deadline already elapsed can result in immediate archival at sync.
Manual Inactive and Graduated accounts never receive grace or automatic activation.

## Re-enrollment

Use only the existing authorized Administrator/Registrar Student List Excel upload.
Reuse `student_number`. Supply explicit `status=Active` and all of `academic_year`,
`semester`, `course`, `year_level`, and `section` for a saved current/upcoming term.
The upload retains its existing required name columns and contact validation.
A missing, rejected, blank-status, or profile-only row cannot cancel expiry or
grant activation. Term scheduling and historical snapshots cannot grant activation.

A qualifying current-term row activates the same account after its transaction
commits and cancels expiry and unsent warnings. A qualifying future row cancels
pending expiry immediately; a student already in grace keeps full access until
the recorded enrollment becomes Active on the saved start date. Their effective
academic profile switches at that start. An account without existing grace
(including manually blocked/new/archived identities) waits without access until
that date. A still-current Active enrollment is preserved during advance import.

Changed enrollment details append history. Identical snapshots can be reused
without duplicate history. Existing passwords/photos are preserved during grace
and re-enrollment. After archival, a complete authorized import can reuse the
archived ID/student-number mapping; the existing temporary-password workflow
provisions new credentials because the old password has been removed.

## What expiry removes and preserves

The live schema was inspected before implementing cleanup. `requests.student_id`,
`request_messages.sender_student_id`, and `student_enrollments.student_id` reference
`students.id` with RESTRICT. `student_notification_reads.student_id` has CASCADE;
the account row is never deleted, so that cascade is never invoked. Attachments
reference requests/messages, and assignment history references requests. Subject
grades/assessments join by student_number. Password reset tokens contain student
IDs without a foreign key.

Expiry retains a non-login row with the same ID and student_number, an archived
timestamp, and blank/zero placeholders required by the existing table. This
minimal mapping preserves FK references and historical student-number joins.
Inactivation/expiry audit dates remain in the outbox; live lifecycle fields clear.

Removed from the live row: password hash, temporary-password expiry/setup state,
name, current course/year/section/term, email, phone, address, birth date, guardian
details, and profile-photo pointer. Password-reset tokens for that ID are deleted.
These are authentication/profile data, not historical academic/request records.

Preserved without rewriting or cascade deletion: enrollment snapshots and their
revisions, subject grades, assessments, requests, messages, attachments, assignment
history, and notification read markers. Staff history remains joined to the archived
student number even though the live name/profile has been cleared. Foreign-key
checks stay enabled during cleanup and the new regression tests.

The currently referenced photo is queued for removal after commit, restricted to
the existing random-name `uploads/students/student_<40 hex>.(jpg|png|webp)` files.
Paths outside that directory, symlinks outside it, and photos still used by another
account are not deleted. Pending cleanup is reported by the CLI. The existing
photo uploader retains older images without owner metadata; this feature does
not guess ownership of pre-existing orphan files or delete unrelated uploads.

## Email delivery and failures

First term inactivation inserts one durable outbox event per student/ended term
inside the status transaction. A unique key prevents repeat events. Sending starts
only after commit, using the existing `knsDeliverMessage` PHPMailer/SMTP helper
and password-reset sender settings. No new credentials or SMS warning are added.

The queue stores IDs, boundary dates, delivery state/attempts, safe failure codes,
and optional cleanup paths. It stores no destination copies, email bodies,
passwords, OTPs, grades, SMTP credentials, or raw transport errors.

The email contains the student name, academic year/semester, official end date,
inactivation and exact expiry with Asia/Manila labels, full-grace-access details,
Registrar/Student Services re-enrollment instructions, and available school profile
contacts. Already re-enrolled students are asked to confirm the upload. When a
qualifying future enrollment is recorded, the notice explicitly says expiry is
cancelled and gives the saved activation date. A current enrollment cancels an
unsent warning rather than delivering an outdated warning.

Queue states: queued, sending, sent, failed, uncertain, cancelled. A per-event
database advisory lock serializes workers. Confirmed sent events never retry.
Known failures retry no sooner than one hour later. Missing/invalid saved email
is recorded as `no_valid_email`; correct it via the existing Admin/Registrar import.
Email failures do not roll back status changes or deny otherwise valid login.
Students List shows safe per-account delivery status; upload results and CLI show
aggregate counts. Sender acceptance is not a guarantee of final inbox delivery.

SMTP cannot atomically commit with MariaDB. A disconnect after possible acceptance
or a crashed sending worker is marked uncertain after review/timeout and is not
automatically resent. This prevents blind duplicate sends. Each event has a fixed
Message-ID `<kns-term-STUDENT_ID-TERM_ID@kns-portal.local>`. Check sender/provider
records before a database operator resolves an uncertain event. Mark it sent if
acceptance is confirmed; return it to failed with next_attempt_at=NULL only if
the provider confirms it was not accepted. Do not reset confirmed sent events.

## Windows Task Scheduler

Configure the existing task; this repository change does not install one.
Use a daily trigger at 00:01 on the XAMPP machine in Manila time (Windows:
Singapore Standard Time, UTC+08:00). Set repetition to every 15 minutes for the
day/indefinitely, so retries and queue batches run without portal visits.

```text
Program/script: C:\xampp\php\php.exe
Add arguments: "C:\xampp\htdocs\kns_portal\php\sync-academic-terms.php"
Start in: C:\xampp\htdocs\kns_portal
```

Enable Run task as soon as possible after a scheduled start is missed. Choose an
account able to read the portal and connect to running MariaDB. Select Do not start
a new instance if one is already running. Test with Task Scheduler's Run action
after applying the migration. Each pass syncs accounts/expiry, retries photo
cleanup, and processes up to 100 eligible emails; subsequent ticks drain backlog.
Request-time checks still enforce exact login/page/API expiry if the task is missed.
Request-time mail errors are isolated from account sync/access.

```powershell
& C:\xampp\php\php.exe C:\xampp\htdocs\kns_portal\php\sync-academic-terms.php
```

Success prints the Manila date/account count and queue/cleanup totals. Account
sync/schema/database failure exits nonzero. Individual mail failures remain in
the reported queue counts and do not stop other accounts.

## Verification

Run `php tests/student_term_grace.php`, `php tests/academic_terms.php`,
`php tests/student_account_setup.php`, and `php tests/student_account_setup_http.php`.
They use disposable databases/web roots, no live student data, and fake/disabled
message delivery. They are functional tests, not a load or stress test.

Manual checks in a separate test database:

1. Linked, unlinked, and enrollment-free Active accounts become term-inactive at
   midnight after term end and retain every normal feature during grace.
2. Verify month-end/leap-year expiry, access one second before the deadline, and
   blocked login/existing APIs exactly at expiry, including when the task was missed.
3. Verify manual Inactive/Graduated, blank/failed uploads, and scheduling alone
   never grant access. Valid current/future imports reuse the same account.
4. Repeat sync and simulate a known mail failure: one event, safe retry, no
   duplicate successful send; future enrollment notices show the activation date.
5. After expiry compare every historical row/attachment and verify the archived
   mapping has no password or personal profile/contact fields and is absent from
   normal Student List filters/counts. Confirm FK checks remain enabled.
