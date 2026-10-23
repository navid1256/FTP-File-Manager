# 7File Manager

A PHP file manager. The current test mode reads user home folders locally from:

`users/`

Each top-level folder under `users/` is treated as one user's home folder. The sidebar lists users from the database and shows their display name. The logged-in user can upload and delete files only inside the folder that matches their username.

FTP columns and migration files are kept for the later FTP-server version.

Normal login is available at `auth.php` and verifies the password hash of an
existing account in `users.username`. The earlier OTP test screen is available
at `auth.php?mode=otp`; it still uses the configured test phone and code.
The registration panel is a preview and does not create accounts or FTP folders.

Run the isolated password login checks with `php tests/PasswordLoginTest.php`.
They use SQLite in memory and never connect to the application database.
