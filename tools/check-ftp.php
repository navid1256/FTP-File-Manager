<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap/ftp.php';
require dirname(__DIR__) . '/libs/lib-ftp.php';
try {
    $client = new FtpHomeClient(fmFtpConfig());
    $result = $client->inspect();
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    echo "Read-only check passed. Write permission has not been tested.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e instanceof FtpProvisionException ? $e->getMessage() . PHP_EOL : "FTP check failed.\n");
    exit(1);
}
