<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (count($argv) !== 2 || !ctype_digit($argv[1]) || (int)$argv[1] < 1) {
    fwrite(STDERR, "Usage: php tools/retry-provision.php USER_ID\nThis explicitly retries FTP creation for one pending/failed account.\n");
    exit(1);
}
require dirname(__DIR__) . '/bootstrap/config.php';
require dirname(__DIR__) . '/bootstrap/ftp.php';
require dirname(__DIR__) . '/libs/lib-ftp.php';
require dirname(__DIR__) . '/libs/lib-registration.php';
try {
    $db = new PDO("mysql:dbname={$database_config->db};host={$database_config->host}",
        $database_config->user, $database_config->pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    fmProvisionStoredUser($db, new FtpHomeClient(fmFtpConfig()), (int)$argv[1]);
    echo "Storage is ready for user ID " . (int)$argv[1] . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e instanceof RegistrationException ? $e->getMessage() . PHP_EOL : "Provisioning failed; inspect the account state.\n");
    exit(1);
}
