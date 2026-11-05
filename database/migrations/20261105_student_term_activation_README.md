# Student term expiration and re-enrollment

Current access and retention rules are extended by the
[three-month grace and email guide](20261106_student_term_grace_README.md).
Apply `20261106_student_term_grace.sql` after the prerequisites. Term-inactive
students retain full access until their exact grace expiry; manual Inactive and
Graduated accounts remain blocked. Refer to that guide for archival and scheduler setup.


## Apply the additive migration

Back up the existing database with phpMyAdmin Export. Select the existing
`kns_portal` database, choose Import, and import
`database/migrations/20261105_student_term_activation.sql`.
The academic-term-management and student-account-setup migrations are prerequisites.
If installing these features for the first time, apply the existing optional
academic-year/semester columns and password-reset prerequisites first, then
student account setup, academic term management, and finally term activation.

The new migration adds one boolean column to `student_enrollments`. It is safe
to rerun. It does not change account status, passwords, photos, enrollment details,
grades, assessments, requests, messages, or IDs. No migration is run automatically.
Existing enrollment snapshots default to unapproved for activation: an old
snapshot or subsequently scheduled term is not proof of a new enrollment import.
Re-import complete Active rows for students who should access a current or
upcoming term after deployment.

Equivalent PowerShell command (run it yourself after backup):

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p kns_portal --execute="SOURCE C:/xampp/htdocs/kns_portal/database/migrations/20261105_student_term_activation.sql"
```

Use your existing database credentials. This change does not install a scheduled task.

## Enrollment rules

Only the existing authorized Administrator/Registrar Students List Excel upload
can approve enrollment. Use the same `student_number` to update the same account.
An Active row must explicitly supply `academic_year`, `semester`, `course`,
`year_level`, and `section`. The saved calendar must contain that year/semester
and its official end date must not have passed in Asia/Manila. Invalid Active
rows fail without changing that student's account, approval, or history.

Missing/blank status never approves enrollment. New accounts with blank status
are Inactive; existing accounts retain their access decision, subject to calendar
expiry. An omitted or rejected row cannot grant access. A previously approved
enrollment still takes effect on its own saved start date.

A qualifying current-term import activates after its row transaction commits.
A qualifying upcoming enrollment remains pending until its start date. If the
student already has access to an ongoing term, that access and academic profile
continue through its official end date. Uploading a future enrollment does not
end a still-current enrollment early. At the new start date, the approved
enrollment supplies the current academic profile.

Active status lasts through 23:59:59 on the official end date in Asia/Manila.
From the next calendar day, all Active accounts without another approved
enrollment currently in effect become Inactive. This includes accounts with no
enrollment rows and legacy rows without saved term links. The latest elapsed
calendar cutoff continues to apply after a new term starts, even if sync missed
the gap between terms. With no elapsed official end date yet, legacy Active
accounts keep their existing access; the first saved end date provides the cutoff.

A term schedule or a historical snapshot cannot reactivate an account.
Manual Inactive and Graduated accounts remain blocked until an explicit,
complete Active import for a saved current/upcoming term succeeds. Explicit
Inactive/Graduated imports also revoke previous activation approvals, including
pending future approvals; the enrollment snapshots themselves remain available.

Changed term/course/year/section/status details append snapshots; identical
reimports reuse the snapshot and can approve it. Status sync updates only account
access and the effective academic profile. Account IDs, existing passwords,
profile photos, and previous enrollment/grade/assessment records are preserved.
Password provisioning/delivery and explicit photo/password changes retain their
existing workflows. A reported delivery failure means the row was saved; it is
not an enrollment validation failure.

## Windows Task Scheduler

Create a task on the machine running XAMPP. Set its trigger to daily at 00:01
in Asia/Manila (Windows time zone: Singapore Standard Time / UTC+08:00). Configure:

```text
Program/script: C:\xampp\php\php.exe
Add arguments: "C:\xampp\htdocs\kns_portal\php\sync-academic-terms.php"
Start in: C:\xampp\htdocs\kns_portal
```

Run under a Windows account able to read the portal configuration and connect to
MariaDB. Enable "Run task as soon as possible after a scheduled start is missed";
choose whether it must run when the user is logged out. MariaDB must be running.
Use Task Scheduler's Run action to check its result and history after installing
the migration. A successful run prints the Manila date and account count and
exits with code 0; migration errors exit with code 1. Manual equivalent:

```powershell
& C:\xampp\php\php.exe C:\xampp\htdocs\kns_portal\php\sync-academic-terms.php
```

Request-time checks still run before student login, protected student pages/APIs
(including existing sessions), and recovery. They enforce the same date rules if
the daily task is missed. The task updates persisted statuses without portal traffic;
it is not required for a request to block an expired account.

## Verification

Run `php tests/academic_terms.php`, `php tests/student_account_setup.php`, and
`php tests/student_account_setup_http.php`. They use disposable databases and
fake/disabled delivery; migrations apply only to those fixtures.

For a manual check, use test accounts in a separate database:

1. Save a term ending today. Active accounts with linked, unlinked, and no
   enrollment rows retain access today and become Inactive tomorrow.
2. Start/schedule the next term without importing those students: login remains blocked.
3. Import the same student number with all required details and explicit Active
   for a saved current term: the same account activates. Invalid/missing/blank-status
   rows cannot reactivate it.
4. Import a valid future enrollment while Inactive: verify it remains Inactive
   before the start date and activates on that date via sync or login.
5. Mark an account Inactive or Graduated; sync must leave it blocked. Only a
   complete, explicit Active import can re-enroll it.
6. Compare account ID, password hash, photo path, old enrollment rows, grades,
   assessments, requests, and messages before/after. Run sync twice to confirm
   repeat runs do not append history or change other data.
