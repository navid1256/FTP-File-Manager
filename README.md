# 7File Manager

A PHP file manager. The current test mode reads user home folders locally from:

`users/`

Each top-level folder under `users/` is treated as one user's home folder. The sidebar lists users from the database and shows their display name. The logged-in user can upload and delete files only inside the folder that matches their username.

FTP columns and migration files are kept for the later FTP-server version.
