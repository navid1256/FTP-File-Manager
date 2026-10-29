<?php

// php tests/FtpRegistrationTest.php -- entirely isolated; no VM or real DB is used.
define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)) . '/');
require BASE_PATH . 'libs/lib-ftp.php';
$registrationFile = BASE_PATH . 'libs/lib-registration.php';
if (!is_file($registrationFile)) {
    throw new RuntimeException('FTP registration has not been implemented.');
}
require $registrationFile;

final class FixtureHomes implements FtpHomeProvisioner
{
    public array $homes = [];
    public int $calls = 0;
    public bool $fail = false;
    public bool $databaseTransactionDuringTransfer = false;
    public function __construct(private PDO $db) {}
    public function homePath(string $username): string { return 'users/' . $username; }
    public function ensureOwnedHome(string $username, string $token): void
    {
        $this->calls++;
        $this->databaseTransactionDuringTransfer = $this->db->inTransaction();
        if ($this->fail || (isset($this->homes[$username]) && $this->homes[$username] !== $token)) {
            throw new FtpProvisionException('Fixture refuses provisioning.');
        }
        $this->homes[$username] = $token;
    }
}

function fixture(): array
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE COLLATE NOCASE, email TEXT UNIQUE COLLATE NOCASE, password TEXT)');
    $db->exec("CREATE TABLE user_storage (user_id INTEGER PRIMARY KEY REFERENCES users(id), folder_path TEXT UNIQUE, quota_bytes INTEGER DEFAULT 2147483648, used_bytes INTEGER DEFAULT 0, reserved_bytes INTEGER DEFAULT 0, status TEXT DEFAULT 'pending', provision_token TEXT UNIQUE, attempt_token TEXT, lease_expires_at TEXT, last_error TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    return [$db, new FixtureHomes($db)];
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function registrationFails(callable $call): void
{
    try { $call(); } catch (RegistrationException $e) { return; }
    throw new RuntimeException('Registration should have failed.');
}

$input = ['username' => 'Alice', 'email' => 'alice@example.invalid', 'password' => 'fixture-password-123', 'password_confirmation' => 'fixture-password-123'];
[$db, $homes] = fixture();
$id = fmRegisterWithFtp($db, $homes, $input);
$row = $db->query('SELECT * FROM user_storage')->fetch(PDO::FETCH_ASSOC);
check($row['status'] === 'ready' && $row['folder_path'] === 'users/alice', 'Successful registration must own a ready FTP path.');
check((int)$row['quota_bytes'] === 2147483648 && (int)$row['used_bytes'] === 0, 'Initial quota must be two GiB and usage zero.');
check(password_verify($input['password'], $db->query('SELECT password FROM users')->fetchColumn()), 'Only a password hash may be stored.');
check(!$homes->databaseTransactionDuringTransfer, 'Do not hold a DB transaction while talking to FTP.');
check(fmFindReadyStorage($db, $id)['folder_path'] === 'users/alice', 'Only ready storage may activate login.');
registrationFails(fn() => fmRegisterWithFtp($db, $homes, $input));
check($homes->calls === 1, 'Duplicate registration must not touch FTP.');

[$db, $homes] = fixture();
$homes->fail = true;
registrationFails(fn() => fmRegisterWithFtp($db, $homes, $input));
check($db->query('SELECT status FROM user_storage')->fetchColumn() === 'failed', 'FTP failure must retain a disabled, recoverable record.');
check(fmFindReadyStorage($db, 1) === null, 'A failed registration must not activate login.');
$failedUser = (object)['id' => 1];
check(!fmAttachProvisionedStorage($db, $failedUser), 'Pending/failed accounts must be blocked by login.');
$homes->fail = false;
fmProvisionStoredUser($db, $homes, 1);
check(fmAttachProvisionedStorage($db, $failedUser) && $failedUser->storage_driver === 'ftp', 'A verified retry may activate the same account.');
check($failedUser->storage_path === 'users/alice', 'Ready login must retain the persisted FTP path.');

[$db, $homes] = fixture();
$homes->homes['alice'] = 'pre-existing-owner';
registrationFails(fn() => fmRegisterWithFtp($db, $homes, $input));
check($homes->homes['alice'] === 'pre-existing-owner', 'Existing directories must not be adopted, overwritten or removed.');

[$db, $homes] = fixture();
$db->exec("CREATE TRIGGER fail_ready BEFORE UPDATE OF status ON user_storage WHEN NEW.status = 'ready' BEGIN SELECT RAISE(ABORT, 'fixture database failure'); END");
registrationFails(fn() => fmRegisterWithFtp($db, $homes, $input));
check(isset($homes->homes['alice']), 'Keep a newly created remote folder if final DB update fails.');
check(fmFindReadyStorage($db, 1) === null, 'A final DB failure must not activate login.');
$db->exec('DROP TRIGGER fail_ready');
fmProvisionStoredUser($db, $homes, 1);
check(fmFindReadyStorage($db, 1) !== null, 'A DB failure must be recoverable using the same owned directory.');

[$db, $homes] = fixture();
$homes->fail = true;
registrationFails(fn() => fmRegisterWithFtp($db, $homes, $input));
$homes->fail = false;
$db->exec("UPDATE user_storage SET status = 'provisioning', lease_expires_at = '2999-01-01 00:00:00'");
$calls = $homes->calls;
registrationFails(fn() => fmProvisionStoredUser($db, $homes, 1));
check($homes->calls === $calls, 'An active provisioning lease must prevent concurrent FTP work.');
$db->exec("UPDATE user_storage SET lease_expires_at = '2000-01-01 00:00:00'");
fmProvisionStoredUser($db, $homes, 1);
check(fmFindReadyStorage($db, 1) !== null, 'An expired provisioning lease must be recoverable.');

foreach (['../alice', 'a/b', "a\r\nb", 'ab', str_repeat('a', 51)] as $invalid) {
    [$db, $homes] = fixture();
    registrationFails(fn() => fmRegisterWithFtp($db, $homes, array_replace($input, ['username' => $invalid])));
    check($homes->calls === 0 && (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'Invalid names must not create DB or FTP state.');
}

echo "FTP registration checks passed (isolated SQLite and fake FTP).\n";
