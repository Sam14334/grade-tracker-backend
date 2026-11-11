<?php

$router = new Router();

$router->get('/health', function (): void {
    jsonSuccess(['status' => 'ok']);
});

// Authentication and email verification
$router->get('/auth/session', 'authSessionController');
$router->post('/auth/register', 'authRegisterController');
$router->post('/auth/verify-otp', 'authVerifyOtpController');
$router->post('/auth/resend-otp', 'authResendOtpController');
$router->post('/auth/login', 'authLoginController');
$router->post('/auth/logout', 'authLogoutController');

// Professor course templates
$router->get('/templates', 'templateListController');
$router->get('/templates/{id}', 'templateShowController');
$router->post('/templates', 'templateCreateController');
$router->put('/templates/{id}', 'templateUpdateController');
$router->delete('/templates/{id}', 'templateDeleteController');

// Professor class standings
$router->get('/professor/standings', 'professorStandingsController');

// Student dashboard and courses
$router->get('/student/dashboard', 'studentDashboardController');
$router->get('/student/courses/{id}', 'studentCourseController');
$router->put('/student/courses/{id}/scores', 'studentSaveScoresController');

// Student enrollments and target grades
$router->post('/enrollments', 'enrollmentCreateController');
$router->delete('/enrollments/{id}', 'enrollmentDeleteController');
$router->put('/enrollments/{id}/target', 'targetGradeController');

return $router;
