# Request Messenger installation

The existing database inspected for this feature contained students and admin_account,
but no request tables. This migration creates real requests, messages, and attachment
metadata. It does not insert sample records or change approval/status behavior.

1. Back up the existing kns_portal database.
2. In XAMPP, open http://localhost/phpmyadmin, select the existing **kns_portal**
   database, choose **Import**, and import **20261101_request_messenger.sql**
   from this directory. The earlier student academic-term migration must already
   have been applied because request submission reads those student fields.
3. Reload the admin and student dashboards. Students can submit real requests in
   My Request, then open **Message** in their request row. Administrators select
   the eye button and then **Messenger**.
4. To use both roles simultaneously, use separate browsers or an incognito session.
   The existing authentication intentionally allows one account role per session.

Files are stored by default in **C:/xampp/kns_portal_private/request_attachments**,
outside the XAMPP htdocs directory. PHP creates this private directory when the
first attachment is saved. Give the Apache/PHP process read/write access there.
For another deployment, set the server environment variable KNS_REQUEST_STORAGE
to an absolute directory outside its document root. The endpoint rejects storage
inside the document root. Do not create a public alias for the private directory.

Each message accepts up to three nonempty files, at most 5 MiB each, with an
optional text body of up to 5000 characters. The installed PHP settings inspected
were upload_max_filesize=40M, post_max_size=40M, max_file_uploads=20, sufficient
for three 5 MiB files. Other deployments need upload_max_filesize at least 5M,
post_max_size above 15M (for example 20M), and max_file_uploads at least 3.
Only verified JPEG, PNG, GIF, and WebP files are displayed inline as images;
all other formats are returned as authenticated downloads with an octet-stream
content type. Original filenames are metadata only; stored filenames are random.

Conversations belong to requests. The signed-in administrator and the owning,
active student are their only participants. The server checks ownership on every
message read, send, preview, and download. Sends use the existing CSRF token.
Polling runs every four seconds while the conversation is open; earlier messages
can be loaded with the history button. There are no bot or AI integrations.

No migration or tests were run during implementation.


## Admin request actions

No additional migration is needed for Reject, Mark as Processing, or Approve & Send.
The existing requests.status column supports all three statuses.

- Pending requests may be processed, approved, or rejected.
- Processing requests may be approved or rejected.
- Approved and rejected requests are final; this feature does not reopen them.
- Remarks are optional and are saved as part of the signed-in admin's response
  in the request conversation. Each explicit status action creates a notification
  under that admin's identity.
- Approve & Send accepts up to 3 PDF/JPG/PNG response files, 5 MiB each. They use
  the same private storage and authenticated download endpoint as Messenger.
  Students open Message on their request row to read the response and download files.
- Remove selected response files with the upload area's × button before choosing
  Processing or Reject.
- The existing database has no module/document repository. Approval sends the
  files actually selected by the administrator; it does not generate missing
  modules, certificates, or grade reports automatically.
- The endpoint checks the admin session, CSRF token, the current database status,
  and a retry token. Status, response message, and attachment metadata are saved
  in one transaction. The admin list and summary refresh after a successful save.
