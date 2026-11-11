<?php

function otpExpiresAt(): string {
    return date('Y-m-d H:i:sP', strtotime('+10 minutes'));
}

function authSession(): array {
    $user = currentUser();
    return [
        'authenticated' => $user !== null,
        'user' => $user,
        'csrfToken' => csrfToken(),
        'pendingOtpEmail' => $_SESSION['pending_otp_email'] ?? null,
    ];
}

function authRegister(array $input): array {
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

    ensureOtpRateLimitColumns($conn);

    // Rate limiting: cooldown check if a code was already generated recently for this email.
    $existingPending = otpFindByEmail($conn, $email);
    if ($existingPending) {
        $lastSent = !empty($existingPending['last_resend_at'])
            ? (int)strtotime($existingPending['last_resend_at'])
            : (!empty($existingPending['created_at']) ? (int)strtotime($existingPending['created_at']) : 0);
        if (time() - $lastSent < 60) {
            $_SESSION['pending_otp_email'] = $email;
            return ['email' => $email, 'redirect' => 'otp.html?email=' . urlencode($email)];
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
    return ['email' => $email, 'redirect' => 'otp.html?email=' . urlencode($email)];
}

function authVerifyOtp(array $input): array {
    $email = strtolower(trim((string)($_SESSION['pending_otp_email'] ?? '')));
    if ($email === '' || currentUser() !== null) {
        throw new ApiException('Please register or sign in to start email verification.', 403);
    }

    $otp = trim((string)($input['otp'] ?? ''));
    if ($otp === '') {
        throw new ApiException('Email and OTP code are required.', 422);
    }

    $conn = db();
    ensureOtpRateLimitColumns($conn);

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
        throw new ApiException("Invalid verification code. {$remaining} attempt(s) remaining.", 422);
    }

    userCreateFromOtp($conn, $pending);
    otpDeleteByEmail($conn, $email);
    unset($_SESSION['pending_otp_email']);

    return ['redirect' => 'index.html?registered=success'];
}

function authResendOtp(): array {
    $email = strtolower(trim((string)($_SESSION['pending_otp_email'] ?? '')));
    if ($email === '' || currentUser() !== null) {
        throw new ApiException('Please register or sign in to start email verification.', 403);
    }

    $conn = db();
    ensureOtpRateLimitColumns($conn);

    $pending = otpFindByEmail($conn, $email);
    if (!$pending) {
        throw new ApiException('No pending verification found for this email.', 404);
    }

    $cooldown = 60;
    $lastResend = !empty($pending['last_resend_at'])
        ? (int)strtotime($pending['last_resend_at'])
        : (!empty($pending['created_at']) ? (int)strtotime($pending['created_at']) : 0);
    $timeSinceLast = time() - $lastResend;

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
        sendResendEmail($email, 'Your New Grade Tracker Verification Code', resendOtpEmailHtml((string)$pending['full_name'], $newOtp));
    } catch (Exception $e) {
        error_log('Resend error: ' . $e->getMessage());
        throw new ApiException('Could not send the verification email. Please try again.', 502);
    }

    $remainingResends = $maxResends - $newResends;
    return [
        'message' => "A new verification code has been sent. ({$remainingResends} resend(s) remaining)",
        'cooldown' => $cooldown,
    ];
}

function authLogin(array $input): array {
    $existing = currentUser();
    if ($existing !== null) {
        return [
            'user' => $existing,
            'redirect' => isProfessor($existing) ? 'professor_dashboard.html' : 'student_dashboard.html',
        ];
    }

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
        $isProfessor = ($roleId === 2 || strcasecmp($roleName, 'Professor') === 0);

        if ($selectedRole === 'professor' && !$isProfessor) {
            throw new ApiException('This account is registered as a Student. Please switch to the Student tab to sign in.', 403);
        }
        if ($selectedRole !== 'professor' && $isProfessor) {
            throw new ApiException('This account is registered as a Professor. Please switch to the Professor tab to sign in.', 403);
        }

        if ($remember) {
            setcookie('remembered_email', $email, time() + (30 * 24 * 60 * 60), '/');
        } else {
            setcookie('remembered_email', '', time() - 3600, '/');
        }

        loginUser($user);
        unset($_SESSION['pending_otp_email']);

        return [
            'user' => currentUser(),
            'redirect' => $isProfessor ? 'professor_dashboard.html' : 'student_dashboard.html',
        ];
    }

    // Redirect to OTP verification if a pending registration exists.
    if (otpFindByEmail($conn, $email)) {
        $_SESSION['pending_otp_email'] = $email;
        return ['redirect' => 'otp.html?email=' . urlencode($email)];
    }

    throw new ApiException('No account found with this email address.', 404);
}

function authLogout(): array {
    logoutUser();
    return ['redirect' => 'index.html'];
}
