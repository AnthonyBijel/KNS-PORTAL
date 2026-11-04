# Request routing

Apply 20261105_request_routing.sql to the existing kns_portal database before
deploying these PHP/JavaScript files. Import it in phpMyAdmin after selecting
kns_portal, or run from the portal directory:

    C:\xampp\mysql\bin\mysql.exe -u root kns_portal -e "SOURCE database/migrations/20261105_request_routing.sql"

The migration adds assigned_office and assignment_revision to requests, an index,
and request_assignment_history. It backfills only unassigned requests by type.
It preserves existing request IDs, details, statuses, messages, files, and all
account records/passwords. Reapplying does not overwrite manually assigned offices
or recreate reassignment history. No table or account is dropped or reset.

Routing uses the existing active rows in admin_account:
- Database role Administrator handles the Admin queue.
- Database role Registrar handles the Registrar queue.
- Database role OSA handles the OSA queue.

Requests belong to an office, not an individual staff account. Every currently
active existing account for that role shares the office queue. Login/session
identities and passwords are unchanged; office sessions remain separate from Admin.

| Office | Automatically assigned request types |
| --- | --- |
| Registrar | Grade Record Request, Certificate of Enrollment, Certificate of Candidacy, Honorable Dismissal |
| OSA | Good Moral Certificate, Student ID Replacement |
| Admin | Module Request, Other Request |

Student submission ignores any posted office value and chooses the office on the
server. Submission retries retain the saved assignment, including later forwarding.
If no active office account exists, requests remain saved in the designated queue.
Admin sees a clear warning in My request. Forwarding to an unstaffed office also
returns a warning and preserves the request there; no account is created.

Registrar and OSA may list/read/update only requests currently assigned to their
own office. These checks also cover summary totals, filter years, notification
feeds/read actions, Messenger sends/history, downloads, and image previews.
Students may see only their own requests. Admin can view/process all requests.

Only an active Administrator may forward requests. Open Request Details, choose
an office under Office Assignment, and click Forward Request. Forwarding works for
Pending, Processing, Approved, and Rejected requests without changing their status.
The existing status workflow still treats Approved/Rejected as final for status
changes. The assignment log records previous/new office, the Admin account, and
time. Request locks, revision checks, and unique client tokens prevent stale or
duplicate forwarding writes. Status/message history and original files are retained.

The new office gets the request in its queue and the previous office loses detail,
conversation, file, and status-update access. Active pages refresh every 15 seconds;
a stale office action is rejected immediately. Existing student forms/layouts stay
the same and the current status is shown by the existing My Requests interface.

Changed files:
- php/request/routing-common.php: mapping, office permissions, availability/history.
- php/request/messenger-common.php: current database role and shared request scope.
- php/request/create-request.php: automatic assignment when saving a student request.
- php/request/records.php: scoped lists/totals/years/details and routing metadata.
- php/admin/requests.php: preserve existing status actions with assignment checks.
- php/admin/forward-request.php: Admin-only POST/CSRF forwarding and audit history.
- php/admin/notifications.php: office-scoped notifications and read actions.
- admin/requests.html: Assigned Office in existing Request Details.
- request/messenger.js and messenger.css: scoped routing controls/history, warning,
  current-office labels, automatic refresh, and stale-access handling.
- database/migrations/20261105_request_routing.sql and this README.
- tests/request_routing_http.cjs: migration, routing/API, and browser checks.
- tests/office_accounts_http.cjs: prior request fixture now belongs to Registrar.

Validation from the portal directory:

    node tests/request_routing_http.cjs --migration-only
    node tests/request_routing_http.cjs
    node tests/request_routing_http.cjs --browser

The routing suite requires existing active Administrator, Registrar, OSA, and two
student accounts. It never seeds, copies, resets, or updates account records.
Non-updatable views read existing identities in a temporary request database;
synthetic requests, files, notifications, assignment logs, and PHP sessions stay in
that disposable database/directory. Temporary local test sessions use the existing
IDs without reading or resetting login passwords. Existing accounts/passwords are
checked unchanged. The old account-registration fixture suite is not run by this
routing suite. Chrome is required for --browser; KNS_TEST_CHROME/KNS_TEST_PHP
override executable paths. KNS_TEST_ARTIFACTS optionally saves screenshots.
