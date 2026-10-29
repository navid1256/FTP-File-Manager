<?php

// Explicit isolated integration check: real VM FTP, SQLite memory, random temporary folder.
// Cleanup is non-recursive and requires the exact ownership token and no extra entries.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!in_array('--live', $argv ?? [], true)) {
    fwrite(STDERR, "Run explicitly: php tests/LiveFtpProvisionTest.php --live\n");
    exit(1);
}
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)) . '/');
require BASE_PATH . 'bootstrap/ftp.php';
require BASE_PATH . 'libs/lib-ftp.php';
require BASE_PATH . 'libs/lib-registration.php';

$config = fmFtpConfig();
$client = new FtpHomeClient($config);
if ($config['protocol'] !== 'ftp') {
    throw new RuntimeException('This VM-only fixture cleanup uses plain FTP; FTPS needs a separate verified cleanup client.');
}
$username = 'verify_' . bin2hex(random_bytes(8));
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password TEXT)');
$db->exec("CREATE TABLE user_storage (user_id INTEGER PRIMARY KEY, folder_path TEXT UNIQUE, quota_bytes INTEGER, used_bytes INTEGER, reserved_bytes INTEGER, status TEXT, provision_token TEXT, attempt_token TEXT, lease_expires_at TEXT, last_error TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$control = @ftp_connect($config['host'], $config['port'], $config['timeout']);
if (!$control || !@ftp_login($control, $config['username'], $config['password']) || !ftp_pasv($control, true)
    || !@ftp_chdir($control, $config['base_path'])) {
    throw new RuntimeException('Cannot open the fixture verification connection.');
}
function fixtureNames($control): array
{
    $names = ftp_nlist($control, '-a');
    if ($names === false) { throw new RuntimeException('Cannot inspect directory entries.'); }
    $names = array_values(array_filter(array_map('basename', $names), static fn($name) => $name !== '.' && $name !== '..'));
    sort($names, SORT_STRING);
    return $names;
}
$before = fixtureNames($control);
$token = null;
$failure = null;
$cleaned = false;
try {
    $password = bin2hex(random_bytes(16));
    $id = fmRegisterWithFtp($db, $client, ['username' => $username, 'email' => $username . '@example.invalid',
        'password' => $password, 'password_confirmation' => $password]);
    $row = $db->query('SELECT * FROM user_storage')->fetch(PDO::FETCH_ASSOC);
    $token = $row['provision_token'];
    if ($row['status'] !== 'ready' || $row['folder_path'] !== $config['base_path'] . '/' . $username) {
        throw new RuntimeException('Unexpected final provisioning state.');
    }
    $client->ensureOwnedHome($username, $token);
    $foreignRejected = false;
    try { $client->ensureOwnedHome($username, bin2hex(random_bytes(32))); }
    catch (FtpProvisionException $e) { $foreignRejected = true; }
    if (!$foreignRejected) { throw new RuntimeException('A different ownership token was incorrectly accepted.'); }
} catch (Throwable $e) {
    $failure = $e;
    $token = $db->query('SELECT provision_token FROM user_storage LIMIT 1')->fetchColumn() ?: null;
} finally {
    try {
        if (is_string($token) && preg_match('/\Averify_[a-f0-9]{16}\z/D', $username)
            && @ftp_chdir($control, $username)) {
            $marker = fopen('php://temp', 'w+b');
            try {
                if (!@ftp_fget($control, $marker, '.filemanager-owner', FTP_BINARY)) {
                    throw new RuntimeException('Ownership is unconfirmed; fixture folder was retained.');
                }
                rewind($marker);
                if (!hash_equals($token, stream_get_contents($marker, 128))
                    || fixtureNames($control) !== ['.filemanager-owner']) {
                    throw new RuntimeException('Unexpected folder content; fixture folder was retained.');
                }
            } finally { fclose($marker); }
            if (!ftp_delete($control, '.filemanager-owner') || !ftp_chdir($control, '..') || !ftp_rmdir($control, $username)) {
                throw new RuntimeException('Fixture cleanup failed.');
            }
            $cleaned = true;
        }
        $after = fixtureNames($control);
        if ($before !== $after) { throw new RuntimeException('Base-directory entries differ after cleanup.'); }
    } finally { ftp_close($control); }
}
if ($failure) {
    echo json_encode(['ok' => false, 'fixture' => $username,
        'state' => $db->query('SELECT status FROM user_storage LIMIT 1')->fetchColumn(),
        'error_code' => $db->query('SELECT last_error FROM user_storage LIMIT 1')->fetchColumn(),
        'database' => 'SQLite in memory only', 'fixture_removed' => $cleaned,
        'base_entries_unchanged' => $before === $after], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
echo json_encode(['ok' => true, 'host' => $config['host'], 'fixture' => $username,
    'database' => 'SQLite in memory only', 'folder_created_and_verified' => true,
    'foreign_owner_rejected' => true, 'fixture_removed' => $cleaned, 'base_entries_unchanged' => $before === $after],
    JSON_UNESCAPED_SLASHES), PHP_EOL;
