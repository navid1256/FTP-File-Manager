<?php

// Run with: php tests/PasswordLoginTest.php
// SQLite is in memory; this script never connects to the project database.
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)) . '/');
define('BASE_URL', 'http://localhost/');
session_save_path(sys_get_temp_dir());
session_start();

require BASE_PATH . 'libs/helpers.php';
require BASE_PATH . 'libs/lib-auth.php';
require BASE_PATH . 'libs/lib-file-manager.php';
require BASE_PATH . 'libs/lib-registration.php';

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(function_exists('loginWithPassword'), 'Password login is not connected yet.');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT)');
$password = bin2hex(random_bytes(16));
$stmt = $pdo->prepare('INSERT INTO users (id, username, password) VALUES (1, :name, :password)');
$stmt->execute([':name' => 'preview_user', ':password' => password_hash($password, PASSWORD_BCRYPT)]);

try {
    check(loginWithPassword('missing_user', $password) === false, 'Unknown account must be rejected.');
    check(loginWithPassword('preview_user', 'incorrect') === false, 'Incorrect password must be rejected.');
    check(loginWithPassword([], $password) === false, 'Malformed username must be rejected.');
    check(loginWithPassword('preview_user', []) === false, 'Malformed password must be rejected.');
    check(!isLoggedIn(), 'Rejected credentials must not create an authenticated session.');

    $sessionBeforeLogin = session_id();
    check(loginWithPassword(' preview_user ', $password) === true, 'Correct credentials must be accepted.');
    check(getCurrentUserId() === 1, 'Session must belong to the authenticated account.');
    check(fmGetCurrentUserFolderName() === 'preview_user', 'FTP folder name must use the account username.');
    check(fmFetchUsersForHomes()[0]->name === 'preview_user', 'Folder listing must use the current username schema.');
    check(session_id() !== $sessionBeforeLogin, 'Successful login must regenerate the session ID.');
    logout();
    check(!isLoggedIn(), 'Logout must clear the authenticated session.');
} finally {
    $_SESSION = [];
    session_destroy();
}

echo "Password login checks passed (isolated SQLite in memory).\n";
