<?php

function userFindByEmail(PDO $conn, string $email): ?array {
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function userFindWithRole(PDO $conn, string $email): ?array {
    $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function userCreateFromOtp(PDO $conn, array $pending): int {
    $stmt = $conn->prepare("
        INSERT INTO users (full_name, email, password_hash, role_id)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (email) DO UPDATE SET
            full_name = EXCLUDED.full_name,
            password_hash = EXCLUDED.password_hash,
            role_id = EXCLUDED.role_id
        RETURNING user_id
    ");
    $stmt->execute([
        $pending['full_name'],
        $pending['email'],
        $pending['password_hash'],
        $pending['role_id'],
    ]);
    return (int)$stmt->fetchColumn();
}
