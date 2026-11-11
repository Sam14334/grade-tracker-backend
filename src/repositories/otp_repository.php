<?php

function otpFindByEmail(PDO $conn, string $email): ?array {
    $stmt = $conn->prepare("SELECT * FROM otp_verifications WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function otpUpsert(PDO $conn, string $email, string $fullName, string $passwordHash, int $roleId, string $otpCode, string $expiresAt): void {
    $stmt = $conn->prepare("
        INSERT INTO otp_verifications (email, full_name, password_hash, role_id, otp_code, expires_at, resend_count, failed_attempts, last_resend_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, 0, CURRENT_TIMESTAMP)
        ON CONFLICT (email) DO UPDATE SET
            full_name = EXCLUDED.full_name,
            password_hash = EXCLUDED.password_hash,
            role_id = EXCLUDED.role_id,
            otp_code = EXCLUDED.otp_code,
            expires_at = EXCLUDED.expires_at,
            created_at = CURRENT_TIMESTAMP,
            resend_count = 0,
            failed_attempts = 0,
            last_resend_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$email, $fullName, $passwordHash, $roleId, $otpCode, $expiresAt]);
}

function otpUpdateFailedAttempts(PDO $conn, string $email, int $failedAttempts): void {
    $stmt = $conn->prepare("UPDATE otp_verifications SET failed_attempts = ? WHERE email = ?");
    $stmt->execute([$failedAttempts, $email]);
}

function otpUpdateCode(PDO $conn, string $email, string $otpCode, string $expiresAt, int $resendCount): void {
    $stmt = $conn->prepare("
        UPDATE otp_verifications
        SET otp_code = ?,
            expires_at = ?,
            resend_count = ?,
            last_resend_at = CURRENT_TIMESTAMP
        WHERE email = ?
    ");
    $stmt->execute([$otpCode, $expiresAt, $resendCount, $email]);
}

function otpDeleteByEmail(PDO $conn, string $email): void {
    $stmt = $conn->prepare("DELETE FROM otp_verifications WHERE email = ?");
    $stmt->execute([$email]);
}
