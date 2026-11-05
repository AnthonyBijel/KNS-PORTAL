# Student temporary-password setup

Current access and retention rules are extended by the
[three-month grace and email guide](20261106_student_term_grace_README.md).
Apply `20261106_student_term_grace.sql` after the prerequisites. Term-inactive
students retain full access until their exact grace expiry; manual Inactive and
Graduated accounts remain blocked. Refer to that guide for archival and scheduler setup.


Apply the migration before using the changed PHP code. Back up the existing local
`kns_portal` database using phpMyAdmin Export, then select **kns_portal > Import**
and choose `database/migrations/20261105_student_account_setup.sql`. This alters
only two columns; it does not replace tables or reset any records. It is safe to
run again. Existing students default to `requires_password_change = 0` and a NULL
expiry, preserving their current passwords and login behavior.

Alternatively, in PowerShell from `C:\xampp\htdocs\kns_portal`:

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p kns_portal --execute="SOURCE C:/xampp/htdocs/kns_portal/database/migrations/20261105_student_account_setup.sql"
```

Enter the existing MariaDB root password (press Enter if your local account uses
no password). The existing `20261102_password_reset.sql` migration and Composer
PHPMailer installation are prerequisites; they are already used by Forgot Password.
No accounts are seeded, and this migration does not send any messages.

Email and SMS use exactly the existing `config/password-reset.php` configuration
and delivery transport. Email retains smtp.gmail.com:587 with authenticated
STARTTLS, PHPMailer, the same sender address, and normal certificate verification.
There are no new credentials. The account-setup message is separate from the OTP
message and uses plain text. Email is tried first, then a valid saved SMS number.
Provider acceptance is reported as sent; final inbox/handset delivery depends on
the provider. Do not enable SMTP debug logging for messages containing passwords.

Apply `20261105_student_term_activation.sql` after academic-term management;
see [the enrollment guide](20261105_student_term_activation_README.md).
Active imports now require explicit Active status and all enrollment details for a
saved current/upcoming term. Blank status creates Inactive new accounts.
New Active imports need a valid saved email or phone. Legacy Password columns
are ignored, including their cell values. New Inactive/Graduated records have no
usable password and remain pending; importing their activation provisions a
temporary password. Reimporting an account with a valid password only updates
student information and never sends another temporary password or alters photos.

Temporary passwords contain 8 random letters and digits from PHP random_int(), are hashed
with password_hash(), and expire in seven days using UTC timestamps. Only their
hash is stored. Delivery failure leaves the saved account pending and is reported
in import results. Correct its contacts with an import if needed, then an
Administrator can use **Send fresh temporary password** under its Students List
status. The action requires POST and CSRF and is restricted to pending Active
accounts. Each attempt creates a new password/hash and invalidates previous
temporary passwords; no old password can be recovered or displayed. Reimporting
a pending account does not implicitly resend it.

A successful temporary login has only a restricted setup session. The shared
session guard rechecks the database on each protected request; it blocks dashboard,
profile, academic APIs, notifications, attachments, and request APIs until setup
is complete. Resends or recovery invalidate older setup sessions. Pending sessions
also cannot continue after expiry. The new password must differ from the temporary
password and meet the existing recovery policy (12 Unicode characters, at most
72 bytes). Successful setup or Forgot Password clears both new fields. Setup
also invalidates existing recovery tokens and rotates the session and CSRF token.

Apache needs mod_rewrite enabled and AllowOverride FileInfo (or All) for this
portal so the root `.htaccess` protects existing student HTML fragment URLs.
This local XAMPP configuration already enables both. Keep the rule when deploying;
servers other than Apache need equivalent routing to `php/auth/student-page.php`.
The surrounding dashboard markup and profile-photo upload logic are unchanged.

Automated checks use disposable fixtures and fake delivery, never real student
credentials or real messages. From the project root run:

```powershell
php tests/student_account_setup.php
php tests/password_reset.php
php tests/student_account_setup_http.php
```