<?php

declare(strict_types=1);

// session and role helpers
function currentUser(): ?array {
    if (!isset($_SESSION['user_id'])) return null;

    return [
        'userId' => (int)$_SESSION['user_id'],
        'email' => (string)($_SESSION['email'] ?? ''),
        'fullName' => (string)($_SESSION['full_name'] ?? ''),
        'role' => (string)($_SESSION['role'] ?? ''),
        'roleId' => (int)($_SESSION['role_id'] ?? 0),
    ];
}

function isProfessor(array $user): bool {
    return (int)$user['roleId'] === 2 || strcasecmp((string)$user['role'], 'Professor') === 0;
}

function requireUser(): array {
    $user = currentUser();
    if ($user === null) throw new ApiException('Please sign in again.', 401);
    return $user;
}

function requireProfessor(): array {
    $user = requireUser();
    if (!isProfessor($user)) throw new ApiException('Professor access is required.', 403);
    return $user;
}

function requireStudent(): array {
    $user = requireUser();
    if (isProfessor($user)) throw new ApiException('Student access is required.', 403);
    return $user;
}

function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['email'] = (string)$user['email'];
    $_SESSION['role'] = (string)$user['role_name'];
    $_SESSION['full_name'] = !empty($user['full_name']) ? (string)$user['full_name'] : (string)$user['email'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    csrfToken();
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function csrfToken(): string {
    if (!isset($_SESSION['score_csrf_token'])) {
        $_SESSION['score_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['score_csrf_token'];
}

function requireCsrf(array $data = []): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? null);
    if (!is_string($token) || !isset($_SESSION['score_csrf_token']) ||
        !hash_equals((string)$_SESSION['score_csrf_token'], $token)) {
        throw new ApiException('Your form expired. Reload this page and try again.', 403);
    }
}

function otpExpiresAt(): string {
    return date('Y-m-d H:i:sP', strtotime('+10 minutes'));
}

function normalizedEmail(mixed $value): string {
    return strtolower(trim(is_string($value) ? $value : ''));
}

function normalizedRole(mixed $value): string {
    return strtolower(trim(is_string($value) ? $value : 'student'));
}

function emailDomain(string $email): string {
    $parts = explode('@', $email);
    return (string)end($parts);
}

// db queries
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

// auth route handlers
function authSessionController(): void {
    $user = currentUser();
    jsonSuccess([
        'authenticated' => $user !== null,
        'user' => $user,
        'csrfToken' => csrfToken(),
        'pendingOtpEmail' => $_SESSION['pending_otp_email'] ?? null,
    ]);
}

function authRegisterController(): void {
    $input = requestData();
    $fullName = trim((string)($input['fullName'] ?? ''));
    $role = normalizedRole($input['role'] ?? 'student');
    $email = normalizedEmail($input['email'] ?? '');
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirmPassword'] ?? '');

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        throw new ApiException('All fields are required.', 422);
    }
    if ($password !== $confirmPassword) {
        throw new ApiException('Passwords do not match.', 422);
    }
    if ($role === 'professor' && emailDomain($email) !== 'pup.edu.ph') {
        throw new ApiException('Professor registration requires an official @pup.edu.ph email address.', 422);
    }
    if ($role !== 'professor' && emailDomain($email) !== 'iskolarngbayan.pup.edu.ph') {
        throw new ApiException('Student registration requires an official @iskolarngbayan.pup.edu.ph email address.', 422);
    }

    $conn = db();
    if (userFindByEmail($conn, $email)) {
        throw new ApiException('Email address is already registered. Please log in.', 409);
    }

    // Cooldown check (60 seconds)
    $existingPending = otpFindByEmail($conn, $email);
    if ($existingPending) {
        $lastSent = !empty($existingPending['last_resend_at'])
            ? (int)strtotime($existingPending['last_resend_at'])
            : (!empty($existingPending['created_at']) ? (int)strtotime($existingPending['created_at']) : 0);
        if (time() - $lastSent < 60) {
            $_SESSION['pending_otp_email'] = $email;
            jsonSuccess(['email' => $email, 'redirect' => 'otp.html?email=' . urlencode($email)]);
            return;
        }
    }

    $roleId = $role === 'professor' ? 2 : 3;
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $otpCode = sprintf("%06d", random_int(100000, 999999));

    otpUpsert($conn, $email, $fullName, $passwordHash, $roleId, $otpCode, otpExpiresAt());

    try {
        sendResendEmail($email, 'Your Grade Tracker Verification Code', otpEmailHtml($fullName, $otpCode));
    } catch (Exception $e) {
        error_log('Resend error: ' . $e->getMessage());
    }

    $_SESSION['pending_otp_email'] = $email;
    jsonSuccess(['email' => $email, 'redirect' => 'otp.html?email=' . urlencode($email)]);
}

function authVerifyOtpController(): void {
    $email = strtolower(trim((string)($_SESSION['pending_otp_email'] ?? '')));
    if ($email === '' || currentUser() !== null) {
        throw new ApiException('Please register or sign in to start email verification.', 403);
    }

    $input = requestData();
    $otp = trim((string)($input['otp'] ?? ''));
    if ($otp === '') {
        throw new ApiException('Email and OTP code are required.', 422);
    }

    $conn = db();
    $pending = otpFindByEmail($conn, $email);
    if (!$pending) {
        throw new ApiException('No pending verification found for this email.', 404);
    }

    if (strtotime($pending['expires_at']) < time()) {
        throw new ApiException('Verification code has expired. Please request a new one.', 422);
    }

    if ($pending['otp_code'] !== $otp) {
        $failedAttempts = (int)($pending['failed_attempts'] ?? 0) + 1;
        $maxAttempts = 5;

        if ($failedAttempts >= $maxAttempts) {
            otpDeleteByEmail($conn, $email);
            unset($_SESSION['pending_otp_email']);
            throw new ApiException(
                "Too many incorrect attempts ({$maxAttempts}/{$maxAttempts}). Verification session expired for security. Please register again.",
                422,
                ['locked' => true]
            );
        }

        otpUpdateFailedAttempts($conn, $email, $failedAttempts);
        $remaining = $maxAttempts - $failedAttempts;
        throw new ApiException("Invalid verification code. {$remaining} attempt(s) remaining.", 422, ['attempts_remaining' => $remaining]);
    }

    userCreateFromOtp($conn, $pending);
    otpDeleteByEmail($conn, $email);

    $user = userFindWithRole($conn, $email);
    if (!$user) {
        throw new ApiException('Registration completed, but automatic sign-in failed. Please log in.', 500);
    }

    loginUser($user);
    unset($_SESSION['pending_otp_email']);

    $isProf = ((int)$user['role_id'] === 2 || strcasecmp($user['role_name'], 'Professor') === 0);
    jsonSuccess([
        'user' => currentUser(),
        'redirect' => $isProf ? 'professor_dashboard.html' : 'student_dashboard.html',
    ]);
}

function authResendOtpController(): void {
    $email = strtolower(trim((string)($_SESSION['pending_otp_email'] ?? '')));
    if ($email === '' || currentUser() !== null) {
        throw new ApiException('Please register or sign in to start email verification.', 403);
    }

    $conn = db();
    $pending = otpFindByEmail($conn, $email);
    if (!$pending) {
        throw new ApiException('No pending verification found for this email.', 404);
    }

    $cooldown = 60;
    $lastSent = !empty($pending['last_resend_at'])
        ? (int)strtotime($pending['last_resend_at'])
        : (!empty($pending['created_at']) ? (int)strtotime($pending['created_at']) : 0);

    $timeSinceLast = time() - $lastSent;
    if ($timeSinceLast < $cooldown) {
        $remaining = $cooldown - $timeSinceLast;
        throw new ApiException("Please wait {$remaining}s before requesting a new code.", 429, ['retry_after' => $remaining]);
    }

    $maxResends = 5;
    $currentResends = (int)($pending['resend_count'] ?? 0);
    if ($currentResends >= $maxResends) {
        throw new ApiException("Maximum resend limit reached ({$maxResends} attempts). Please register again.", 429);
    }

    $newOtp = sprintf("%06d", random_int(100000, 999999));
    $newResends = $currentResends + 1;
    otpUpdateCode($conn, $email, $newOtp, otpExpiresAt(), $newResends);

    try {
        sendResendEmail($email, 'Your New Grade Tracker Verification Code', otpEmailHtml((string)$pending['full_name'], $newOtp));
    } catch (Exception $e) {
        error_log('Resend error: ' . $e->getMessage());
        throw new ApiException('Could not send the verification email. Please try again.', 502);
    }

    $remainingResends = $maxResends - $newResends;
    jsonSuccess([
        'message' => "A new verification code has been sent. ({$remainingResends} resend(s) remaining)",
        'cooldown' => $cooldown,
    ]);
}

function authLoginController(): void {
    $existing = currentUser();
    if ($existing !== null) {
        jsonSuccess([
            'user' => $existing,
            'redirect' => isProfessor($existing) ? 'professor_dashboard.html' : 'student_dashboard.html',
        ]);
        return;
    }

    $input = requestData();
    $email = normalizedEmail($input['email'] ?? $input['loginEmail'] ?? $input['loginUsername'] ?? '');
    $password = (string)($input['password'] ?? $input['loginPassword'] ?? '');
    $selectedRole = normalizedRole($input['role'] ?? $input['userRole'] ?? 'student');
    $remember = !empty($input['rememberMe']);

    if ($email === '' || $password === '') {
        throw new ApiException('Please enter both email and password.', 422);
    }

    if ($selectedRole === 'professor') {
        if (emailDomain($email) !== 'pup.edu.ph') {
            throw new ApiException('Professor sign-in requires an official @pup.edu.ph email address.', 422);
        }
    } elseif (emailDomain($email) !== 'iskolarngbayan.pup.edu.ph') {
        throw new ApiException('Student sign-in requires an official @iskolarngbayan.pup.edu.ph email address.', 422);
    }

    $conn = db();
    $user = userFindWithRole($conn, $email);

    if ($user) {
        if (!password_verify($password, $user['password_hash'])) {
            throw new ApiException('Invalid password!', 401);
        }

        $roleName = $user['role_name'];
        $roleId = (int)$user['role_id'];
        $isProf = ($roleId === 2 || strcasecmp($roleName, 'Professor') === 0);

        if ($selectedRole === 'professor' && !$isProf) {
            throw new ApiException('This account is registered as a Student. Please switch to the Student tab to sign in.', 403);
        }
        if ($selectedRole !== 'professor' && $isProf) {
            throw new ApiException('This account is registered as a Professor. Please switch to the Professor tab to sign in.', 403);
        }

        if ($remember) {
            setcookie('remembered_email', $email, time() + (30 * 24 * 60 * 60), '/');
        } else {
            setcookie('remembered_email', '', time() - 3600, '/');
        }

        loginUser($user);
        unset($_SESSION['pending_otp_email']);

        jsonSuccess([
            'user' => currentUser(),
            'redirect' => $isProf ? 'professor_dashboard.html' : 'student_dashboard.html',
        ]);
        return;
    }

    // Redirect to OTP verification if a pending registration exists.
    if (otpFindByEmail($conn, $email)) {
        $_SESSION['pending_otp_email'] = $email;
        jsonSuccess(['redirect' => 'otp.html?email=' . urlencode($email)]);
        return;
    }

    throw new ApiException('No account found with this email address.', 404);
}

function authLogoutController(): void {
    logoutUser();
    jsonSuccess(['redirect' => 'index.html']);
}