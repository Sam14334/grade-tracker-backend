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

/**
 * Ensures required rate limiting columns exist on otp_verifications table
 */
function ensureOtpRateLimitColumns(PDO $conn): void {
    static $columnsEnsured = false;
    if ($columnsEnsured) return;
    try {
        $conn->exec("
            ALTER TABLE otp_verifications ADD COLUMN IF NOT EXISTS resend_count INT DEFAULT 0;
            ALTER TABLE otp_verifications ADD COLUMN IF NOT EXISTS failed_attempts INT DEFAULT 0;
            ALTER TABLE otp_verifications ADD COLUMN IF NOT EXISTS last_resend_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;
        ");
        $columnsEnsured = true;
    } catch (Exception $e) {
        error_log("OTP migration check: " . $e->getMessage());
    }
}
