<?php
require __DIR__ . '/src/config.php';
header('Content-Type: text/plain');
echo 'PHP: ', PHP_VERSION, "\n";
echo 'pdo_pgsql: ', extension_loaded('pdo_pgsql') ? 'enabled' : 'MISSING', "\n";
foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_SSLMODE'] as $k) {
    echo $k, ': ', configValue($k) ?? 'NOT LOADED', "\n";
}
try {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        requiredConfig('DB_HOST'), requiredConfig('DB_PORT'),
        requiredConfig('DB_NAME'), requiredConfig('DB_SSLMODE'));
    new PDO($dsn, requiredConfig('DB_USER'), requiredConfig('DB_PASSWORD'));
    echo "CONNECT: OK\n";
} catch (Throwable $e) {
    echo 'CONNECT: ', get_class($e), ': ', $e->getMessage(), "\n";
}