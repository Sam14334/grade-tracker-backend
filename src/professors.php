<?php

declare(strict_types=1);

/* =========================================================================
   1. Professor Class Standings & Analytics
   ========================================================================= */

function professorStandings(int $professorId): array {
    $conn = db();
    $standingCourses = [];

    $templatesStmt = $conn->prepare("
        SELECT template_id, course_code, course_title, course_unit, class_code
        FROM grade_templates
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $templatesStmt->execute([$professorId]);

    while ($row = $templatesStmt->fetch()) {
        $templateId = (int)$row['template_id'];
        $components = loadTemplateComponents($conn, $templateId);
        $standingCourses[$templateId] = [
            'id' => $templateId,
            'code' => (string)$row['course_code'],
            'title' => (string)$row['course_title'],
            'components' => $components,
            'students' => [],
        ];
    }

    $enrollmentStmt = $conn->prepare('
        SELECT ec.enrollment_id, ec.template_id, u.full_name, u.email
        FROM enrolled_courses ec
        JOIN grade_templates gt ON gt.template_id = ec.template_id
        JOIN users u ON u.user_id = ec.user_id
        WHERE gt.user_id = ? AND ec.user_id <> ? AND u.role_id <> 2
        ORDER BY ec.template_id, u.full_name, u.email
    ');
    $enrollmentStmt->execute([$professorId, $professorId]);

    while ($enrollment = $enrollmentStmt->fetch()) {
        $templateId = (int)$enrollment['template_id'];
        if (!isset($standingCourses[$templateId])) continue;

        $components = $standingCourses[$templateId]['components'];
        $scores = loadEnrollmentScores($conn, (int)$enrollment['enrollment_id'], $templateId);
        $result = calculateCourseGrade($components, $scores);

        $performance = summarizeComponentPerformance($components, $scores);
        $componentResults = $performance['components'];
        $gradedWeight = $performance['gradedWeight'];
        $currentPercentage = $gradedWeight > 0 && $result['status'] !== 'Invalid weights'
            ? $performance['percentage'] : null;

        $standingCourses[$templateId]['students'][] = [
            'enrollmentId' => (int)$enrollment['enrollment_id'],
            'student' => $enrollment['full_name'] ?: $enrollment['email'],
            'email' => $enrollment['email'],
            'percentage' => $currentPercentage,
            'grade' => $result['grade'],
            'status' => $result['status'] === 'Invalid weights' ? 'Invalid weights'
                : ($currentPercentage === null ? 'Not Started' : ($result['status'] === 'Complete' ? 'Complete' : 'Provisional')),
            'periods' => $result['periods'],
            'gradedWeight' => $gradedWeight,
            'components' => $componentResults,
        ];
    }

    return [
        'courses' => array_values($standingCourses),
        'updatedAt' => date('c'),
        'gradeScale' => array_column(pupGradeScale(), 1),
        'gradeScalePairs' => pupGradeScale(),
    ];
}

/* =========================================================================
   2. Route Handler (Dispatched directly by router)
   ========================================================================= */

function professorStandingsController(): void {
    $user = requireProfessor();
    session_write_close();
    jsonSuccess(professorStandings($user['userId']));
}