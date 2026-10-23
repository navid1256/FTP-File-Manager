<?php
define('SITE_TITLE', '7File Manager');
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)) . '/');
$fmScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$fmHost = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
define('BASE_URL', $fmScheme . '://' . $fmHost . '/');
define('FM_STORAGE_QUOTA_BYTES', 2 * 1024 * 1024 * 1024);
define('FM_STORAGE_WARNING_BYTES', (int)(1.8 * 1024 * 1024 * 1024));
define('FM_TEST_LOGIN_PHONE', '09123456789');
define('FM_TEST_LOGIN_OTP', '123456');
define('FM_TEST_LOGIN_USER_NAME', 'navid1256');
