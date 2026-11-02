# Student password recovery

The existing login.php opens a two-step recovery modal; there is no separate
forgot-password page. Recovery applies to active student accounts only.

## Sender setup for this XAMPP installation

The email/contact_number stored on each student is the destination. A Gmail login
on a phone does not configure the web server to send mail. Configure a sender
account separately.

Open C:/xampp/kns_portal_private/password-reset.php (outside htdocs) and fill in:

- smtp_username: the Gmail account used to SEND codes;
- smtp_password: that account's Google App Password;
- smtp_from: optional; defaults to smtp_username;
- twilio_sid, twilio_token, twilio_from: needed only for SMS.

For Gmail, enable 2-Step Verification on the sender account and create an
[App Password](https://myaccount.google.com/apppasswords). Use the generated App
Password, not the normal Gmail password or the student's portal password.
[Google instructions](https://support.google.com/accounts/answer/185833)

For SMS, get the Account SID, Auth Token, and SMS-capable sender from the
[Twilio Console](https://console.twilio.com/). A student's saved phone number is
the recipient, not the Twilio sender. Trial accounts restrict eligible recipients;
see [Twilio trial setup](https://www.twilio.com/docs/usage/tutorials/how-to-use-your-free-trial-account).

Save the private PHP file with valid PHP syntax. This file is loaded on each
request, so no Apache restart is needed for edits to this file.
Keep credentials out of chat, public files, and source control.

The private file path can be overridden with KNS_PASSWORD_RESET_CONFIG. That
override must point outside the web server's public directory. Nonempty provider
environment variables override the private file.

## Installation

1. Run composer install to install the locked PHPMailer dependency.
2. Import 20261102_password_reset.sql into the existing kns_portal database.
   The migration uses CREATE TABLE IF NOT EXISTS, preserving existing records.
   It also supports the pre-existing password_reset_tokens columns.
3. Configure the following environment variables for the Apache/PHP process:

| Variable | Value |
| --- | --- |
| KNS_SMTP_USERNAME | Gmail address used to send recovery messages |
| KNS_SMTP_PASSWORD | Gmail app password, not the normal account password |
| KNS_SMTP_FROM_EMAIL | Optional authorized sender; defaults to the Gmail address |
| KNS_TWILIO_ACCOUNT_SID | Twilio account SID |
| KNS_TWILIO_AUTH_TOKEN | Twilio auth token |
| KNS_TWILIO_FROM_NUMBER | SMS-capable Twilio sender in E.164 format |

For XAMPP, put Apache SetEnv directives in a private file such as
C:/xampp/apache/conf/extra/kns-password-reset.conf and include that file from
Apache's httpd.conf. Restart Apache afterward. Keep actual credentials outside
C:/xampp/htdocs, source control, screenshots, and client JavaScript. OS environment
variables inherited by Apache work too. CLI environment variables do not
automatically configure an already-running Apache process.

Gmail uses smtp.gmail.com:587 with authenticated STARTTLS and PHPMailer.
Twilio uses its HTTPS Messages API with Basic authentication. Do not disable TLS
certificate verification. If PHP cannot validate a provider certificate, configure
PHP's trusted CA bundle in php.ini.

See [PHPMailer](https://github.com/PHPMailer/PHPMailer),
[Google app passwords](https://support.google.com/accounts/answer/185833), and
[Twilio Messages](https://www.twilio.com/docs/messaging/api/message-resource).

No delivery credentials are included in this project. An unconfigured or failing
provider returns an error inside the modal and invalidates the undelivered code;
the UI never reports it as sent. There is no developer bypass, printed OTP, or
OTP log.

## Flow and limits

- request_password_reset.php accepts JSON action=lookup with student_number,
  then action=send with student_number and channel=email or sms.
- Lookup returns masked saved contacts; client-provided destinations are ignored.
  Invalid, inactive, and missing-contact accounts share a recovery-unavailable
  message. Showing saved contact choices necessarily reveals limited account
  information, so lookup is limited to 30 requests per IP per 15 minutes.
- OTPs use random_int(100000, 999999). Only password_hash(otp) is stored in
  token_hash. An OTP expires after ten minutes and permits five wrong attempts.
- Sends have a 60-second cooldown and a five-per-hour limit per student across
  channels and browser sessions, plus 15 send attempts per IP per hour.
  Failed provider calls count toward these limits. Missing provider configuration is rejected before creating a code and does not consume the per-student send limit.
- A resend immediately invalidates earlier OTPs and verified recovery grants.
- verify_password_reset.php consumes the OTP once. It creates only a server-side,
  session-bound grant, valid for at most five minutes and never beyond the OTP's
  original ten-minute expiry.
- reset_password.php requires that grant and matching new passwords. Passwords
  need at least 12 Unicode characters and at most 72 bytes, consistent with the
  existing login's bcrypt byte limit. It hashes the new password, updates only
  the verified student's password_hash, and invalidates all their reset records
  atomically. It does not read, send, or display the old password.
- Verification is limited to 60 attempts per IP per 15 minutes; password updates
  to 15 per IP per 15 minutes. All endpoints enforce POST, JSON, body-size limits,
  the existing session CSRF token, and prepared SQL.
- Database transactions lock the student before reset rows to serialize sends,
  verification, and password changes. Remote delivery happens after committing.
- Recovery timestamps use a UTC database connection. The UI displays durations.
- Success asks the student to sign in normally; recovery does not auto-login.

A scheduled maintenance task can delete password_reset_rate_limits rows whose
expires_at is before UTC_TIMESTAMP(), and reset tokens created more than seven
days ago. Retain at least an hour of reset history so per-student send limits work.

## Verification

Run php tests/password_reset.php from the project root. It creates connection-local
temporary tables that shadow the real tables, uses an in-memory delivery fake, and
checks masking, hash-only storage, attempts, expiry, cooldowns, replay, session
binding, replacement of old codes, delivery failures, and password updates.
Temporary fixtures disappear when the process exits. The script refuses HTTP.

Real Gmail and Twilio delivery still requires configured credentials and a
delivery check to a controlled, authorized student contact. No real messages are
sent by the automated tests.