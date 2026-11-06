# KNS Portal — Current System Documentation

**Reviewed:** November 6, 2026  
**Repository:** AnthonyBijel/KNS-PORTAL, main branch

This guide describes the current implementation in the repository and its migration notes. It supplements the earlier CS ELECT 1 report and includes the student lifecycle, office accounts, request routing, and staff settings added in the latest updates.

## 1. System Overview

KNS Portal is a web-based student services system for Kolehiyo ng Subic. It gives students one place to view announcements and academic records, submit and track service requests, exchange messages with the responsible office, and review their profile.

School staff use role-based dashboards to publish announcements, manage student records, handle requests, and update their own staff settings.

## 2. User Roles

| Role | Main access |
| --- | --- |
| Student | View announcements, grades and assessment scores; submit requests; message the assigned office; manage the student photo and account password. |
| Administrator | Manage student and office accounts, schedule academic terms, upload results, publish announcements, view and forward all requests, and configure the Administrator profile. |
| Registrar | Manage the student list and student/academic Excel imports; handle requests assigned to Registrar; publish announcements and manage the Registrar profile. |
| OSA | Handle requests assigned to OSA; publish announcements and manage the OSA profile. OSA accounts do not have Results or upload access. |

Students sign in with a student number. Staff sign in with a username through the staff option on the login page. A staff account must be active and its database role controls its access.

Only the Administrator can create or deactivate Registrar and OSA accounts. Deactivation blocks sign-in and preserves request history. Accounts in the same office share that office's request queue.

## 3. Main Features

### Student dashboard and announcements

The student dashboard includes Overview, Academic, My Request, and Student Information. Overview presents school announcements. Staff can publish announcements with a category and optional media; posts can also have an expiry date. Notifications link students to relevant announcements or request updates.

### Academic records and student imports

Students can view subject grades and individual assessment scores by academic year, semester, subject, and available grading filters.

The Administrator and Registrar can download Excel templates and import student information, subject grades, and assessments. Student number is the account key: re-importing the same number updates the existing account. A complete approved enrollment import records term, course, year level, and section history, so earlier academic-term records remain associated with the student when the current course or section changes.

New or pending student accounts use the temporary-password setup flow. The student must set a permanent password before using protected portal features. Forgotten passwords can be recovered through a saved contact when the email/SMS delivery configuration is available.

### Requests, office routing, and messaging

Students can request a module, grade record, certificate of enrollment, good moral certificate, certificate of candidacy, honorable dismissal, student ID replacement, or another service. A request has a reference number, academic term, purpose, status, and optional supporting files.

The server assigns requests to an office by request type:

| Office queue | Request types |
| --- | --- |
| Registrar | Grade Record Request, Certificate of Enrollment, Certificate of Candidacy, Honorable Dismissal |
| OSA | Good Moral Certificate, Student ID Replacement |
| Administrator | Module Request, Other Request |

Students can see only their own requests. Registrar and OSA staff can access only their office's assigned requests. The Administrator can see all requests and forward a request to another office; assignment changes are recorded in history.

Staff can move a Pending request to Processing, Approved, or Rejected. Approved and Rejected are final for status changes. Each request has a Messenger conversation for questions, replies, and file delivery. Message text supports up to 5,000 characters; supporting or response files are limited to three files of up to 5 MiB each.

### Notifications and staff settings

Student notifications cover announcements, staff messages, and request decisions. Staff notifications cover announcements, requests/messages, and account activity. Read receipts are saved per account.

Staff settings include profile and contact details, verified email changes, password security, notification preferences, light/dark appearance, and English/Filipino interface language. Staff role and office assignment cannot be changed through profile settings.

### Academic terms and student account expiry

The Administrator maintains the academic calendar. Terms have a school year, semester, start date, and official end date. Term status is calculated using the configured Asia/Manila timezone.

An explicit Active enrollment imported by the Administrator or Registrar for a saved current or upcoming term approves access. Scheduling a term or retaining an old history row does not activate a student by itself. Manual Inactive and Graduated accounts stay blocked until a valid re-enrollment is imported.

At the end of a term, an Active account becomes term-inactive at midnight on the next Manila calendar day. It keeps normal portal access for three calendar months and receives an email notice when a valid address and mail service are configured. At the exact expiry time, the account is archived: login and current personal/contact details are removed from the live profile, while the student-number mapping and academic, request, message, attachment, and enrollment history are retained. A complete approved re-enrollment can reuse the same student number.

## 4. Typical Workflows

### Student

1. Sign in with the student number and password.
2. Read announcements and open Academic to review grades and scores.
3. Submit a service request and use its reference and status to track it.
4. Open Messenger on the request to communicate with the assigned office or receive files.
5. Review Student Information, update the profile photo if needed, and sign out.

### Staff

1. Sign in using the staff username.
2. Open the office's request queue, review request details and files, and use Messenger when clarification is needed.
3. Update the status or send an approved response. The Administrator can forward a request if another office should handle it.
4. Publish announcements or update staff profile and settings according to the role's permissions.

### Student enrollment and term management

1. The Administrator schedules the term with its real dates.
2. The Administrator or Registrar imports a complete student row with the explicit Active status and matching term details.
3. The portal applies enrollment at the term start and appends changed enrollment details to history.
4. At term end, the grace period and expiry rules apply. The student can retain access during grace or regain access through a valid re-enrollment import.

## 5. Technical Structure

| Area | Repository location |
| --- | --- |
| Login and session checks | login.php and php/auth/ |
| Student pages | dashboard/ and module/ |
| Administrator and office pages | admin/ |
| Student, staff, and academic APIs | php/student/, php/admin/, and php/module/ |
| Requests, Messenger, and attachments | request/ and php/request/ |
| Database migrations and deployment notes | database/migrations/ |
| Automated checks | tests/ |

The application uses PHP 8.2, HTML, CSS, JavaScript, MySQL/MariaDB, and PDO. Composer manages PhpSpreadsheet for Excel workbooks and PHPMailer for email. The main connection uses the existing kns_portal database. Uploaded request files use private storage outside the public web directory; profile and announcement media use the portal's upload folders.

## 6. Local Setup and Operations

1. Place the project under the XAMPP web root, for example C:/xampp/htdocs/kns_portal. Run Apache and MySQL/MariaDB.
2. Use PHP 8.2, install Composer dependencies with composer install, and check them with composer check-platform-reqs.
3. Configure the database connection in db.php. The repository relies on the existing core database tables; it is not a one-click installer for a completely empty database.
4. Back up the database before applying pending SQL files from database/migrations/. Follow each migration's README for prerequisites and import order. Migrations are not applied automatically.
5. Configure email/SMS settings through the private configuration or environment variables described in the migration guides. Keep credentials outside the public web root and source control.
6. Keep Apache mod_rewrite and AllowOverride enabled, or configure equivalent routing on another server. Set the private request attachment location and writable upload folders for the web-server account.

Student term synchronization, expiry, photo cleanup, and queued term-end emails use php/sync-academic-terms.php. The migration guide recommends a Windows Task Scheduler job at 00:01 Asia/Manila, repeating every 15 minutes. The task is not installed automatically. Request-time account checks still enforce access and expiry if the task is missed.

## 7. Current Implementation Notes

- The current staff ID column is an unsigned TINYINT, which limits stored staff records to 255, including inactive accounts. Increasing this requires a database change.
- The portal depends on an existing database schema and ordered incremental migrations; the repository does not provide a complete fresh-install schema.
- Email, SMS, private file storage, and scheduled synchronization need server-side configuration.
- The test scripts are in tests/. The repository's migration notes describe isolated regression checks; this documentation does not claim that runtime or load tests were run during review.
