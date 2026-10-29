# 7File Manager

A PHP file manager. The current test mode reads user home folders locally from:

`users/`

Each top-level folder under `users/` is treated as one user's home folder. The sidebar lists users from the database and shows their display name. The logged-in user can upload and delete files only inside the folder that matches their username.

FTP columns and migration files are kept for the later FTP-server version.

Normal login is available at `auth.php` and verifies the password hash of an
existing account in `users.username`. The earlier OTP test screen is available
at `auth.php?mode=otp`; it still uses the configured test phone and code.
FTP registration is implemented behind `registration_enabled` in the ignored
`bootstrap/ftp.local.php` file. It remains disabled until the storage schema and
FTP write access have been verified. This phase uses username, email and password;
real mobile OTP remains deferred. The fixed development OTP is disabled when
real FTP registration is enabled.

Run the isolated password login checks with `php tests/PasswordLoginTest.php`.
They use SQLite in memory and never connect to the application database.

## FTP account provisioning

The VM account is shared by the application. Individual employee accounts live
in SQL; their directory is `users/<lowercase_username>` relative to the FTP
login directory. Passwords for the VM belong only in `bootstrap/ftp.local.php`.
Use `bootstrap/ftp.example.php` as its template. `ftp` is explicitly selected for
the private VM test network; `ftps` requires TLS on both channels and certificate
verification, optionally with a trusted CA PEM file. No insecure TLS fallback is used.

Read-only connection and base-directory check:

```powershell
php tools/check-ftp.php
```

Prepare SQL matching the actual `users.id` type (read-only):

```powershell
php tools/storage-schema.php
```

`Database/migrations/002_create_user_storage.sql` is a template, not an import-ready
dump. The renderer handles the difference between `INT UNSIGNED` in the currently
configured database and `BIGINT` in `Database/ftp_filemanager.sql`.
Do not run the old `001_add_user_ftp_columns.sql` for this shared-account design;
it stores per-user FTP credentials, which this feature does not use.

Before applying, inspect `SHOW CREATE TABLE users`, confirm the target database,
and take a backup with the database tools, for example:

```text
mysqldump --host=DB_HOST --user=DB_USER --password DB_NAME > before-user-storage.sql
```

Only after explicit approval, apply the additive table creation:

```powershell
php tools/storage-schema.php --apply
```

This adds `user_storage` without changing existing user rows. Existing local
accounts are not silently migrated. `ON DELETE RESTRICT` prevents deleting an
account while its storage registration still exists. DDL does not have an ordinary
transaction rollback; recovery uses the verified backup. Do not drop a populated
storage table to undo the migration: it carries ownership and provisioning evidence.

Registration first reserves the user and storage row in a short transaction, then
creates the remote directory and a private `.filemanager-owner` token marker,
then marks storage `ready`. Failed or interrupted attempts cannot log in; they
retain state for review. No recursive compensation deletes remote data. A foreign
pre-existing directory is never adopted. The marker is internal metadata and must
be excluded from future user upload/download/listing operations and user byte totals.

After inspecting a failed account, an administrator may explicitly retry it:

```powershell
php tools/retry-provision.php USER_ID
```

One attempt owns a five-minute lease. An interrupted attempt can be retried after
expiry. Retries verify the original ownership token. If a folder exists but has
no matching marker, keep it for manual inspection; do not delete or reuse it.

Verification commands:

```powershell
php tests/PasswordLoginTest.php
php tests/FtpRegistrationTest.php
php tests/LiveFtpProvisionTest.php --live
```

The last command is an explicit real-VM check: it creates a random temporary
directory, uses SQLite in memory, and removes only that owned directory when it
contains exactly the ownership marker. It compares base-directory entries before
and after cleanup. It does not mutate the real SQL database.

**Current boundary:** this slice provisions accounts and folders. The existing
file browser still operates on local files. FTP-backed accounts do not silently
receive a second local folder; they see an unavailable-storage message until the
file-operation migration is implemented. The two-GiB quota is initialized in SQL;
upload quota enforcement and SQL activity logging are separate pending work.
