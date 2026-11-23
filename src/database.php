<?php

function db(): PDO {
    static $conn = null;
    if ($conn instanceof PDO) return $conn;

    try {
        $host = requiredConfig('DB_HOST');
        $port = requiredConfig('DB_PORT');
        $dbname = requiredConfig('DB_NAME');
        $user = requiredConfig('DB_USER');
        $password = requiredConfig('DB_PASSWORD');
        $sslmode = requiredConfig('DB_SSLMODE');
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
        $conn = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('Database initialization failed: ' . $e->getMessage());
        throw new ApiException('Database connection failed. Check .env credentials.', 503);
    }

    return $conn;
}
