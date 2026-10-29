<?php

// Copy to ftp.local.php and enter the password there, never in Git or chat.
return [
    'registration_enabled' => false,
    'host' => '192.168.170.128',
    'port' => 21,
    // Explicit FTPS on port 21. Use 'ftp' only for the chosen private test network.
    'protocol' => 'ftps',
    'username' => 'ftp_filemanager',
    'password' => '',
    // Relative to the directory entered after FTP login, NOT a Windows path.
    'base_path' => 'users',
    'timeout' => 15,
    // Optional PEM CA file for the VM's FTPS certificate; verification stays on.
    'ca_file' => '',
];
