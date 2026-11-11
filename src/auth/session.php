<?php

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
