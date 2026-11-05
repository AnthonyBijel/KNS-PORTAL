# Academic Term Management

Back up the existing `kns_portal` database in phpMyAdmin first. Select that existing
database, open Import, and import `20261105_academic_term_management.sql` before
using Manage Terms. The migration creates calendar and enrollment-history tables,
adds a term-driven inactivity flag, and snapshots each existing student's current
academic profile. It preserves existing accounts, password hashes, photos,
requests, grades, assessments, and notification data. It is safe to rerun.

From PowerShell in `C:\xampp\htdocs\kns_portal`, the equivalent command is:

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p kns_portal --execute="SOURCE C:/xampp/htdocs/kns_portal/database/migrations/20261105_academic_term_management.sql"
```

Use your existing MariaDB password (Enter for an empty local password). The prior
student-account-setup migration remains a prerequisite for the previously added
temporary-password flow. This migration adds no sample terms, dates, or accounts.

## Calendar and permissions

The Administrator schedules terms through Manage Terms in the Students List
banner. Registrar accounts retain Results access and can read terms and history;
only the Administrator can schedule them. OSA and student sessions cannot access
the staff term API. All writes require POST and the existing CSRF token.

Terms store independent academic year, semester, start date, and official end date
records. Existing semesters are `1st Semester`, `2nd Semester`, and `Summer`.
Academic years use consecutive years, `YYYY-YYYY`. Inputs use ISO calendar dates.
The first term can define the current calendar with its actual start date; no
example dates are pre-filled. Empty calendars and gaps show no active term.

`config/academic-terms.php` configures the school timezone (`Asia/Manila`, matching
the existing announcement date convention) and term-driven inactivity. PHP's host
timezone is not used. Status is computed on every read: Scheduled before start,
Active on every calendar day from start through official end, and Completed from
the following day. A future term never activates early. Overlaps include shared
boundary dates, so the next start must be later than the previous official end.
A database calendar lock serializes concurrent scheduling and a unique key also
prevents duplicate year/semester entries. There are no edit/delete term endpoints.

## Enrollment and access

The existing student Excel import remains the enrollment workflow; the workbook,
upload cards, instructions, filters, and student table are unchanged by this
feature. Save the calendar term first, then import its academic_year/semester and
course/year_level/section. Matching student numbers reuse the same account and
password. Each changed academic profile or enrollment decision appends a snapshot;
re-uploading identical data adds no duplicate snapshot. Course transfers preserve
prior snapshots, including changes within the same term.

Future enrollment is saved immediately in history, while the student's current
course/section remains the profile of the term that is still active. After the
start date, the profile switches to the new enrollment. Only the latest enrollment
decision within a term controls access; all revisions remain readable in history.

As requested, a managed enrollment's end makes the account Inactive until an
Active re-enrollment becomes effective. Accounts without a current enrollment
also remain inactive before a scheduled start date. Term-driven inactivity is
tracked separately, allowing automatic reactivation of that same account on the
new term's start date. Manually Inactive and Graduated accounts stay blocked;
set status=Active through the existing import when intentionally re-enrolling
one of those accounts. Account passwords and photos are never replaced by term
transitions. Scheduling alone never enrolls a student in another term.

Calendar checks run before login, student-only pages/APIs, password recovery, and
admin term/list reads. Therefore a still-open student session cannot bypass the
end date. For status updates exactly at local midnight even without traffic,
configure Windows Task Scheduler to run daily:

```text
Program: C:\xampp\php\php.exe
Arguments: C:\xampp\htdocs\kns_portal\php\sync-academic-terms.php
Start in: C:\xampp\htdocs\kns_portal
```

Use the same school's timezone for the task schedule. Request-time checks remain
authoritative if the task is missed.

## Preserved history

View Term History lists all saved terms and their date-derived status. View
records shows paginated enrollment revisions, subject grades, and assessments.
Historical grade/assessment label variations are matched when reading, without
rewriting those old rows. Incoming grade labels now follow the existing student
import's year/semester conventions. Grade upserts retain their existing keys,
which include student number and academic term, so a new term does not replace
an earlier term's scores.

The migration captures the academic profile actually present today. It cannot
reconstruct earlier courses or section changes already overwritten before this
feature existed. Existing grades and assessments remain available under their
saved academic-year/semester labels; add the matching term's actual dates to
browse them in term history. Unknown legacy term dates remain unknown, with no
invented expiry. When a saved term matches those labels, the preserved enrollment
snapshots are linked to it. No account or academic record is hard-deleted.

Run the isolated tests with `php tests/academic_terms.php`. Existing account and
recovery regression checks remain in their original test scripts. Tests use a
disposable database/web root and no real student credentials or provider calls.
Use `php tests/academic_terms.php --browser` to include headless Chrome checks for
the modal, keyboard focus, responsive layout, saved history, and surrounding UI
geometry. Chrome uses an isolated disposable profile; screenshots are written to
`KNS_TEST_ARTIFACTS` or the system temporary `kns-academic-term-review` folder.
The tests also check concurrent overlapping schedules and existing sessions at
the end-date boundary.
