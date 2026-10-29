<?php

// Default is read-only: print the migration matching users.id in the configured DB.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap/config.php';
try {
    $db = new PDO("mysql:dbname={$database_config->db};host={$database_config->host}",
        $database_config->user, $database_config->pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $columns = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $idType = null;
    foreach ($columns as $column) {
        if ($column['Field'] === 'id') {
            $idType = $column['Type'];
        }
    }
    if (!is_string($idType) || !preg_match('/\A(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?\z/iD', $idType)) {
        throw new RuntimeException('Unsupported users.id type.');
    }
    $sql = str_replace('{{USER_ID_TYPE}}', $idType,
        file_get_contents(dirname(__DIR__) . '/Database/migrations/002_create_user_storage.sql'));
    if (!in_array('--apply', $argv, true)) {
        echo $sql;
        exit;
    }
    // Only explicit --apply performs DDL. Existing tables are never dropped or replaced.
    $db->exec($sql);
    echo "Created user_storage. Existing users were not modified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Storage schema command failed; database error code: ' . $e->getCode() . PHP_EOL);
    exit(1);
}
