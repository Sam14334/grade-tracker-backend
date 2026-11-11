<?php

function studentDashboardController(): void {
    $user = requireStudent();
    jsonSuccess([
        'user' => $user,
        'courses' => studentDashboard($user['userId']),
        'gradeScale' => array_column(pupGradeScale(), 1),
        'targetGrades' => allowedTargetGrades(),
        'csrfToken' => csrfToken(),
    ]);
}

function studentCourseController(array $params): void {
    $user = requireStudent();
    jsonSuccess(courseDetail($user['userId'], (int)$params['id']));
}

function studentSaveScoresController(array $params): void {
    $user = requireStudent();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(saveCourseScores($user['userId'], (int)$params['id'], $data));
}

function enrollmentCreateController(): void {
    $user = requireStudent();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(enrollCourse($user['userId'], $data));
}

function enrollmentDeleteController(array $params): void {
    $user = requireStudent();
    requireCsrf(requestData());
    jsonSuccess(unenrollCourse($user['userId'], (int)$params['id']));
}

function targetGradeController(array $params): void {
    $user = requireStudent();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(saveTargetGrade($user['userId'], (int)$params['id'], $data));
}
