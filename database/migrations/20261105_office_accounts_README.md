# Dynamic office accounts

Apply 20261105_office_accounts.sql to the existing kns_portal database before
deploying the updated PHP files. In XAMPP, import the file with phpMyAdmin, or run:

    C:\xampp\mysql\bin\mysql.exe -u root kns_portal -e "SOURCE database/migrations/20261105_office_accounts.sql"

The migration is safe to reapply on this MariaDB 10.4 installation. It removes
only the singleton CHECK constraint, enables auto-increment on the existing ID,
adds is_active (existing accounts default to active), and adds a unique index on
school_email. No tables, records, account IDs, passwords, students, or request
history are dropped or reset. Existing foreign-key column types remain intact.

Before applying on another installation, check for existing duplicate non-NULL
school_email values. Resolve duplicates deliberately before adding the index;
the migration does not edit or remove any conflicting records. The existing
TINYINT UNSIGNED staff IDs are retained, so this installation supports up to 255
staff records, including inactive ones. Widening the ID and its dependent foreign
keys would be a separate schema change.

Account data stays in admin_account:

- admin_name: Full Name
- username: Username (existing unique index)
- school_email: Email (new unique index)
- school_contact: Phone Number
- role: Registrar or OSA
- password_hash: password_hash() output, never exposed in API responses
- is_active: 1 for active, 0 for deactivated

The school_name for a new office account is copied from the creating Admin.
Only a currently active database account with role Administrator may list,
create, or deactivate office accounts. A posted role never grants Admin access.
The form accepts Registrar and OSA only. Passwords require at least 12 characters
and at most 72 bytes, matching the portal's existing password-recovery policy.

Staff sign in through the existing Admin choice on login.php using their username
and password. Existing Administrator sessions retain admin_id. Registrar/OSA
sessions instead receive office_id and office_role. Both offices can use Overview
(including Create/Delete Post), Requests, and their own My Profile page. Registrar
also has full Results/Students List access, including student and grade uploads.
OSA cannot access Results or either upload endpoint, even through direct URLs.
Office account registration, listing, and deactivation remain Admin-only.

Office profiles reuse the Admin Profile layout without account-management buttons
or modals. Each office can update its own name, email, phone, address, and photo.
School Name is read-only for offices; role, username, active status, and other
accounts cannot be changed through the profile form. Page, upload, and request
endpoints check the current database identity and active status. Deactivated
accounts cannot sign in or continue using an old office session. This role/profile
update uses existing columns and does not require another SQL migration.

Office Accounts keeps the current five-column modal. Name cells also show phone
numbers; Email / Username cells show both contact and login details. Successful
creation/deactivation refreshes real accounts. Deactivation verifies the signed-in
Admin's current database password with password_verify(), then changes only
is_active. Request messages and foreign-key links remain. Five incorrect
confirmation passwords in five minutes are throttled in the current Admin session.

GET php/admin/office-accounts.php lists accounts. POST action=create creates an
office account; POST action=deactivate deactivates one. Both writes require the
existing session CSRF token. The public response fields are id, full_name,
username, email, phone_number, role, and is_active.

Validation:

- php tests/office_accounts.php uses temporary tables only.
- tests/office_accounts_http.cjs runs authentication/API/UI regression checks in
  a separately named temporary database and temporary web root. It never modifies
  existing school accounts. Run with Node 22+ and XAMPP PHP/MySQL available.

Run the integration tests from the portal root:

    node tests/office_accounts_http.cjs
    node tests/office_accounts_http.cjs --browser

The browser option requires Chrome (or KNS_TEST_CHROME). KNS_TEST_PHP can select
another PHP executable. Optional KNS_TEST_ARTIFACTS saves browser screenshots.
Tests use fake credentials only and delete their own database/web root afterward.

Files changed for dynamic office accounts:

- admin/profile.php, admin/admin.js, admin/office-registration.css, and
  admin/office-accounts.css: existing modal fields, live data, and scoped states.
- php/admin/office-accounts.php and office-account-common.php: Admin-only API,
  validation, safe account responses, registration, and soft deactivation.
- php/auth/staff-accounts.php, admin_login.php, admin_logout.php, and session.php:
  shared staff login with separate office session identity.
- admin/dashboard.php, login.php, and php/request/messenger-common.php:
  assigned-role entry to existing requests and active-account checks.
- database/migrations/20261105_office_accounts.sql and this README: safe schema
  extension and deployment instructions.
- tests/office_accounts.php and tests/office_accounts_http.cjs: service,
  authentication/API, and browser regression coverage.

Role/profile access files: php/auth/staff-access.php centralizes the role policy.
admin/dashboard.php, admin/admin.js, admin/overview.php, admin/create_post.php,
admin/delete_post.php, and admin/results.php reuse the existing pages and tools.
admin/profile.php and php/admin/upload_profile_photo.php support own office
profiles. php/admin/upload_students.php and upload_grades.php allow Registrar
and Admin only. tests/office_accounts_http.cjs covers these permissions, profile
isolation, real workbook imports, and browser layout/navigation.
