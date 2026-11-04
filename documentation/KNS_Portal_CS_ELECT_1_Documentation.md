# KNS Portal: Student Services System
**[Student Name] | CS ELECT 1**
**Kolehiyo ng Subic | Updated: November 2, 2026**

## 1. Introduction
KNS Portal is a web-based student services system for Kolehiyo ng Subic. It brings school announcements, academic results, service requests, request conversations, and student information into one portal. Students use their own accounts, while the administrator maintains records and responds to requests through a separate dashboard.

This revision describes the implementation in the repository as of November 2, 2026, including the database-backed features added after the original October documentation.

## 2. Objectives
- Present subject grades and assessment scores by academic year, semester, subject, and grading period.
- Let students submit service requests, track decisions, and exchange messages and files with the administrator.
- Keep school announcements and unread request updates visible through the portal.
- Maintain student accounts and academic records through spreadsheet imports.
- Provide student password recovery through saved email or mobile contacts when delivery services are configured.

## 3. Problem Statement
Students need a clear way to find academic information, receive school updates, and follow up on service requests. When these activities use separate channels, students may repeat inquiries or lose track of a request. KNS Portal groups these activities in one dashboard and keeps each request's reference, status, conversation, and attachments together.

## 4. System Overview
The student dashboard contains **Overview**, **Academic**, **My Request**, and **Student Information**. The administrator dashboard contains **Overview**, **Results** (the student list and spreadsheet uploads), **My request**, and **Admin Profile**.

PHP endpoints retrieve and save records in the existing `kns_portal` database. JavaScript loads dashboard sections and communicates with those endpoints. The notification bell leads users to the relevant announcement or request. Request files are served through an authenticated endpoint from storage outside the public web directory.


<!-- pagebreak -->


## 5. System Design
### Student and administrator access
Students sign in with a student number and password; only Active student accounts can sign in. The administrator uses the admin login option. The current admin authentication query selects the account with ID 1. A browser session holds one account and one role at a time; separate browsers or an incognito session allow simultaneous role testing.

### Announcements and academic records
Overview shows announcements categorized as Academic, Events, or Guidelines. The administrator can post descriptions with supported image or video media. Announcement links can be shared.

Academic shows the signed-in student's subject grades and activity, quiz, exam, and project scores. Available records populate the term controls. The server resolves the student from the session. The administrator's Results page provides a searchable student list, academic-term and course filters, and spreadsheet uploads.

### Requests and decisions
Supported types are Module Request, Grade Record Request, Certificate of Enrollment, Good Moral Certificate, Certificate of Candidacy, Honorable Dismissal, Student ID Replacement, and Other Request. Submission includes academic year, semester, purpose, optional subject, and supporting files.

New requests receive a unique reference and Pending status. The administrator can move Pending requests to Processing, Approved, or Rejected; Processing requests can be Approved or Rejected. Approved and Rejected are final. Optional remarks and selected response files appear in the request conversation.

### Messenger and attachments
Each request has a conversation between its owning student and the administrator. Messages support up to 5,000 characters and up to three attachments, each at most 5 MiB. Verified images can be previewed; other files are authenticated downloads. **Approve & Send** accepts PDF, JPG/JPEG, or PNG response files under the same count and size limits. Messenger polls about every four seconds while open.

### Notifications and profiles
Student notifications include announcements, administrator messages, and request decisions. Admin notifications include student requests and student messages. Both provide All/Unread views, Mark all as read, history, and persistent read receipts. Visible dashboard tabs normally refresh notifications every five seconds.

Student Information displays personal, academic, contact, and guardian information. Students can upload their profile photo. Admin Profile supports school/contact details and an admin photo. Both photo uploads accept validated JPG/JPEG, PNG, or WebP files up to 2 MiB.


<!-- pagebreak -->


## 6. Technology Stack
- **HTML and CSS:** page structure, forms, tables, dashboard layout, and modal styling.
- **JavaScript:** section loading, academic filters, upload feedback, Messenger, notification polling, sharing, and password recovery.
- **PHP 8.2:** server endpoints, validation, authentication, uploads, and recovery logic. The locked PhpSpreadsheet package requires PHP 8.2.
- **MySQL/MariaDB with PDO:** persistent records, prepared queries, and transactions; development uses XAMPP/Apache.
- **Composer:** installs the locked dependencies. `phpoffice/phpspreadsheet` reads Excel workbooks; `phpmailer/phpmailer` sends recovery email.
- **Delivery integrations:** the recovery implementation uses Gmail SMTP with STARTTLS for email and the Twilio HTTPS Messages API for SMS. Each channel requires its own sender configuration.

### Main data groups
- `students` and `admin_account`: accounts, password hashes, profile details, and student academic-term fields.
- `announcements`: announcement category, description, and optional media.
- `subject_grades` and `grade_assessments`: subject grades and individual assessment records, associated with student numbers and academic terms.
- `requests`: request reference, owner, type, term, purpose, and status.
- `request_messages` and `request_attachments`: conversation entries, status-event metadata, and metadata for privately stored files.
- `student_notification_reads` and `admin_notification_reads`: separate read receipts for each user.
- `password_reset_tokens` and `password_reset_rate_limits`: hashed recovery codes, expiry/attempt information, and rate limits.

### Access and storage behavior
Successful login regenerates the session and replaces the previous identity. Passwords are verified against stored hashes. Request reads, messages, previews, and downloads check the account role and student ownership. Request changes and password-recovery calls require the session CSRF token.

Request submissions and message/status actions use retry tokens to avoid duplicate saves. Request status, response messages, and attachment metadata are saved together in a transaction. Request attachments are separate from public announcement media and profile photos under `uploads/`.

### Main source locations
`login.php` and `php/auth/` handle access and recovery. `dashboard/` and `module/` contain student screens. `admin/` and `php/admin/` contain administrator screens and actions. `php/request/` and `request/` implement requests and Messenger. Notification endpoints are in `php/student/` and `php/admin/`; migrations are in `database/migrations/`.


<!-- pagebreak -->


## 7. Implementation / Workflow
### Student workflow
1. Open `http://localhost/kns_portal/login.php` and sign in with an Active student account.
2. Read Overview announcements and open notification items to view their related content.
3. Open Academic, choose an available academic year and semester, and select a subject. Use grading-period and assessment-type filters to inspect scores.
4. Open My Request, complete the request fields, optionally attach supporting files, and submit. Use the request list to track the reference and status.
5. Choose **Message** on a request to send follow-up questions, read the administrator's response, and download delivered files.
6. Open Student Information to review saved details or upload a profile photo. Contact Student Services for record corrections.
7. Log out when finished.

### Administrator workflow
1. Choose the admin login option and sign in. Open Results to manage the student list and uploads.
2. Download the student template and upload an `.xlsx` or `.xls` workbook up to 10 MiB. Required fields are student_number, first_name, last_name, course, year_level, and section. New accounts also require a password.
3. Re-upload an existing student number to update that account. An omitted optional column preserves existing data; a blank cell in a supplied optional column generally clears that field. A blank password preserves the existing password. Valid rows are saved even if other rows fail; review the row-level results.
4. Upload grade or assessment `.xlsx` workbooks up to 10 MiB using the supplied templates. Supported sheets are Subject Grades, Assessments, and Assessment Scores. Student numbers must already exist. A row error rolls back the entire grade/assessment upload.
5. Use Overview to publish categorized announcements with optional media.
6. Open My request and the request's details through the eye button. Review supporting files, use Messenger for clarification, then choose Mark as Processing, Reject, or Approve & Send. Response files are optional and must be selected by the administrator.
7. Review notifications and update school/contact details or the admin photo in Admin Profile.

### Student password recovery
From the existing login page, open **Forgot password**, enter a student number, and choose a masked saved email or mobile contact. Request and verify the six-digit code, then enter matching new passwords. There is no separate recovery page.

Codes expire after ten minutes, allow five incorrect attempts, and have a 60-second resend cooldown. Sending is limited per student and IP. A resend invalidates earlier codes and recovery grants. Successful verification creates a session-bound grant for at most five minutes, bounded by the code's original expiry. A new password needs at least 12 Unicode characters and at most 72 bytes. Successful reset returns the student to normal sign-in.


<!-- pagebreak -->

## 8. Advantages
- One dashboard groups announcements, personal academic records, requests, and profile information.
- Request references, status summaries, and conversations support follow-up and file delivery.
- Spreadsheet templates support bulk account maintenance and academic-record uploads.
- Persistent read receipts help track updates; configured recovery channels let active students reset forgotten passwords.

## 9. Limitations
- The portal needs a running PHP/database server and browser access. Records depend on timely school uploads.
- The current administrator login targets account ID 1; general multi-admin account management is not implemented.
- Approval sends the administrator's selected files. A document repository and automatic module, certificate, or grade-report generation are not implemented.
- Notifications use in-page polling. Browser push and email/SMS request notifications are not implemented.
- Password recovery needs an Active account, a valid saved contact, and a configured sender. Missing or failed delivery configuration produces an error.
- Students can update their profile photo; saved personal and academic details require school-maintained records.
- The repository provides incremental migrations, rather than a complete installer for an empty database. Automated recovery checks do not verify every portal workflow or real email/SMS delivery.

## 10. Future Enhancements
- Add a complete, versioned base database schema and repeatable installation.
- Add an approved document repository and document-generation workflows.
- Expand administrator accounts, permissions, activity reporting, announcement search, and optional request-notification channels.
- Extend academic reporting and automated/browser acceptance checks.

## 11. Conclusion
KNS Portal connects academic records, announcements, requests, administrator decisions, conversations, notifications, and profiles. Its dashboards support the student service process from submission through response and file delivery. Configured email or SMS recovery supports account access. Accurate school records and a correctly configured deployment remain essential.

<!-- pagebreak -->


## Appendix A. Local Setup and Verification
### Runtime and existing database
1. Place the project at `C:/xampp/htdocs/kns_portal`. Start Apache and MySQL/MariaDB in XAMPP.
2. Use PHP 8.2 with the extensions required by `composer.lock`; run `composer install` and `composer check-platform-reqs` from the project root. Photo handling needs Fileinfo/GD; SMS uses cURL; SMTP/TLS needs OpenSSL.
3. Configure the connection in `db.php` for the existing `kns_portal` database. The baseline students, admin_account, announcements, subject_grades, and grade_assessments tables must already exist. The migrations below are not a complete fresh-install schema.

### Incremental migration order
Back up the existing database and check which migrations have already been applied. Import outstanding files through phpMyAdmin in this order:
1. `database/migrations/20261029_add_student_academic_terms.sql`
2. `database/migrations/20261101_request_messenger.sql`
3. `database/migrations/20261101_student_notifications.sql`
4. `database/migrations/20261101_admin_notifications.sql`
5. `database/migrations/20261102_password_reset.sql`

The academic-term and student-notification migrations contain ALTER TABLE statements that must not be blindly re-imported. Review the matching migration README files for feature-specific instructions.

### Files and delivery configuration
Allow Apache/PHP to write the required public upload folders and the private request storage folder. The XAMPP default is `C:/xampp/kns_portal_private/request_attachments`. For another deployment, set `KNS_REQUEST_STORAGE` to an absolute directory outside the document root. For three 5 MiB attachments, use upload_max_filesize of at least 5M, post_max_size above 15M, and max_file_uploads of at least 3.

Recovery loads sender settings from `C:/xampp/kns_portal_private/password-reset.php`, or a private path selected by `KNS_PASSWORD_RESET_CONFIG`. Nonempty provider environment variables override private-file values. Email uses KNS_SMTP_USERNAME, KNS_SMTP_PASSWORD, and optional KNS_SMTP_FROM_EMAIL. SMS uses KNS_TWILIO_ACCOUNT_SID, KNS_TWILIO_AUTH_TOKEN, and KNS_TWILIO_FROM_NUMBER. Configure these for the Apache/PHP process; keep actual secrets outside public files and source control. See `database/migrations/20261102_password_reset_README.md` for sender setup and recovery limits.

### Verification guidance
Run `php tests/password_reset.php` from the project root after the required tables exist. It uses connection-local temporary tables and an in-memory delivery fake; it does not send real messages. Real delivery needs a separate check with an authorized contact.

For a manual acceptance pass, use separate student/admin sessions to check account access, imports, academic filters, request submission, file downloads, status transitions, notification read state, profile photos, and logout. This revision was checked against source files; it does not claim those runtime checks were executed.
