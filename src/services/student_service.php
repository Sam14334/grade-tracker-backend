<?php

function studentDashboard(int $userId): array {
    $conn = db();
    $courses = [];

    foreach (enrollmentListByUser($conn, $userId) as $row) {
        $templateId = (int)$row['template_id'];
        $components = loadTemplateComponents($conn, $templateId);
        $scores = loadEnrollmentScores($conn, (int)$row['enrollment_id'], $templateId);
        $result = calculateCourseGrade($components, $scores);

        $courses[] = [
            'id' => $templateId,
            'templateId' => $templateId,
            'enrollmentId' => (int)($row['enrollment_id'] ?? 0),
            'code' => $row['course_code'],
            'title' => $row['course_title'],
            'units' => (float)$row['course_unit'],
            'classCode' => $row['class_code'],
            'components' => $components,
            'grade' => $result['grade'],
            'percentage' => $result['percentage'],
            'gradeStatus' => $result['status'],
            'periods' => $result['periods'],
            'target' => (float)$row['target_grade'],
        ];
    }

    return $courses;
}

function courseDetail(int $userId, int $enrollmentId): array {
    $conn = db();
    $course = enrollmentFindForUser($conn, $enrollmentId, $userId);
    if (!$course) throw new ApiException('Course not found in your enrollments.', 404);

    $templateId = (int)$course['template_id'];
    $components = loadTemplateComponents($conn, $templateId);
    $scores = loadEnrollmentScores($conn, $enrollmentId, $templateId);
    $entriesByComponent = [];
    foreach ($scores as $score) {
        $entriesByComponent[$score['component_id']][] = $score;
    }

    $examComponents = array_values(array_filter($components, fn($component) => $component['is_exam']));

    return [
        'course' => [
            'enrollmentId' => (int)$course['enrollment_id'],
            'templateId' => $templateId,
            'code' => $course['course_code'],
            'title' => $course['course_title'],
            'units' => (float)$course['course_unit'],
            'target' => (float)$course['target_grade'],
        ],
        'components' => $components,
        'entries' => $entriesByComponent,
        'examComponents' => $examComponents,
        'periods' => gradingPeriods(),
        'gradeScale' => pupGradeScale(),
        'result' => calculateCourseGrade($components, $scores),
        'csrfToken' => csrfToken(),
    ];
}

function saveCourseScores(int $userId, int $enrollmentId, array $input): array {
    $conn = db();
    $course = enrollmentFindForUser($conn, $enrollmentId, $userId);
    if (!$course) throw new ApiException('Course not found in your enrollments.', 404);

    $components = loadTemplateComponents($conn, (int)$course['template_id']);
    $componentIds = array_column($components, 'id');
    $componentsById = array_column($components, null, 'id');
    $postedEntries = $input['entries'] ?? null;

    if (!is_array($postedEntries) || !$components) {
        throw new ApiException('The form was incomplete. Please reload and try again.', 422);
    }

    $errors = [];
    foreach (array_keys($postedEntries) as $id) {
        if (!in_array((string)$id, $componentIds, true)) {
            $errors[] = 'An entry does not belong to this course.';
        }
    }

    $newScores = [];
    foreach ($components as $component) {
        $componentId = $component['id'];
        $rows = $postedEntries[$componentId] ?? null;
        if (!is_array($rows) || count($rows) === 0) {
            $errors[] = 'Each component must have at least one entry row.';
            continue;
        }

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row) ||
                !is_scalar($row['raw_score'] ?? null) ||
                !is_scalar($row['total_items'] ?? null) ||
                !is_scalar($row['assessment_title'] ?? null)) {
                $errors[] = 'An entry has invalid fields.';
                continue;
            }

            $title = trim((string)$row['assessment_title']);
            if ($title === '') $title = 'Entry ' . ($index + 1);
            $raw = trim((string)$row['raw_score']);
            $max = trim((string)$row['total_items']);

            // A completely blank score row is a placeholder, not a zero.
            if ($raw === '' && $max === '') continue;

            $validMax = filter_var($max, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            $titleLength = preg_match_all('/./us', $title);
            if ($titleLength === false || $titleLength > 100 || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $raw) ||
                $validMax === false || (float)$raw > $validMax) {
                $errors[] = $component['name'] . ': use a score from 0 to the maximum (up to two decimal places), a positive whole-number maximum, and an entry name of at most 100 characters.';
                continue;
            }

            $newScores[] = [
                'component_id' => $componentId,
                'assessment_title' => $title,
                'raw_score' => $raw,
                'total_items' => $max,
            ];
        }
    }

    if ($errors) {
        $errors = array_values(array_unique($errors));
        throw new ApiException($errors[0], 422, ['errors' => $errors]);
    }

    try {
        $conn->beginTransaction();
        scoreDeleteForEnrollment($conn, $enrollmentId, $userId);

        $insertStatements = [];
        foreach (componentTables() as $period => $table) {
            $insertStatements[$period] = scoreInsertStatement($conn, $period);
        }

        foreach ($newScores as $entry) {
            $component = $componentsById[$entry['component_id']];
            $insertStmt = $insertStatements[$component['period']];
            $insertStmt->execute([
                $entry['assessment_title'],
                $entry['raw_score'],
                $entry['total_items'],
                $enrollmentId,
                $userId,
                $component['database_id'],
            ]);
            if ($insertStmt->rowCount() !== 1) {
                throw new RuntimeException('Enrollment or component changed while saving.');
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if ($e instanceof ApiException) throw $e;
        error_log('Course scores could not be saved: ' . $e->getMessage());
        throw new ApiException('Could not save your scores. Your previous scores are unchanged. Please try again.', 500);
    }

    return ['saved' => true];
}

function enrollCourse(int $userId, array $input): array {
    $classCode = $input['classCode'] ?? $input['templateCodeInput'] ?? $input['class_code'] ?? '';
    $classCode = is_string($classCode) ? strtoupper(trim($classCode)) : '';

    if ($classCode === '') throw new ApiException('Please enter a class code.', 422);

    $conn = db();
    $template = templateFindByClassCode($conn, $classCode);
    if (!$template) {
        throw new ApiException("Invalid class code: '$classCode' not found.", 404);
    }

    $enrollmentId = enrollmentCreate($conn, $userId, (int)$template['template_id']);
    if ($enrollmentId === null) {
        throw new ApiException("You're already enrolled in this course.", 409);
    }

    return [
        'enrollmentId' => $enrollmentId,
        'redirect' => 'student_dashboard.html?enrolled=success#templates',
    ];
}

function unenrollCourse(int $userId, int $enrollmentId): array {
    $conn = db();
    if (!enrollmentDeleteForUser($conn, $enrollmentId, $userId)) {
        throw new ApiException('Course not found in your enrollments.', 404);
    }
    return ['redirect' => 'student_dashboard.html?unenrolled=success#grades'];
}

function saveTargetGrade(int $userId, int $enrollmentId, array $input): array {
    $target = filter_var($input['targetGrade'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($target === false || !in_array($target, allowedTargetGrades(), true)) {
        throw new ApiException('Select a valid target grade from 1.00 to 3.00.', 422);
    }

    $conn = db();
    if (!enrollmentUpdateTarget($conn, $enrollmentId, $userId, $target)) {
        throw new ApiException('Could not save the target grade. Please try again.', 404);
    }

    return ['targetGrade' => $target, 'redirect' => 'student_dashboard.html?target=saved#grades'];
}
