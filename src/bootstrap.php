<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http/exceptions.php';
require_once __DIR__ . '/http/response.php';

$allowedOrigins = appAllowedOrigins();
$requestOrigin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
// The API answers with a different ACAO per origin, so caches must key on Origin.
header('Vary: Origin');
if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-HTTP-Method-Override');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}
$crossSite = $allowedOrigins !== [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$crossSite = $allowedOrigins !== [];
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $crossSite ? true : $isHttps,
    'httponly' => true,
    'samesite' => $crossSite ? 'None' : 'Lax',
]);
session_start();

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ApiException) {
        jsonResponse(
            ['success' => false, 'error' => $e->getMessage()] + $e->extra(),
            $e->status()
        );
    }

    error_log('Unhandled API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['success' => false, 'error' => 'Unexpected server error. Please try again.'], 500);
});

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/http/request.php';
require_once __DIR__ . '/http/router.php';
require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/auth/mailer.php';
require_once __DIR__ . '/auth/email_templates.php';
require_once __DIR__ . '/support/grade_calculator.php';
require_once __DIR__ . '/support/course_components.php';
require_once __DIR__ . '/support/template_form.php';
require_once __DIR__ . '/support/validation.php';
require_once __DIR__ . '/repositories/user_repository.php';
require_once __DIR__ . '/repositories/otp_repository.php';
require_once __DIR__ . '/repositories/template_repository.php';
require_once __DIR__ . '/repositories/enrollment_repository.php';
require_once __DIR__ . '/repositories/score_repository.php';
require_once __DIR__ . '/services/auth_service.php';
require_once __DIR__ . '/services/template_service.php';
require_once __DIR__ . '/services/student_service.php';
require_once __DIR__ . '/services/standing_service.php';
require_once __DIR__ . '/controllers/auth_controller.php';
require_once __DIR__ . '/controllers/template_controller.php';
require_once __DIR__ . '/controllers/student_controller.php';
require_once __DIR__ . '/controllers/professor_controller.php';
