<?php

declare(strict_types=1);

/* =========================================================================
   1. Database Queries (Enrollments & Scores)
   ========================================================================= */

function enrollmentListByUser(PDO $conn, int $userId): array {
    $stmt = $conn->prepare("
        SELECT gt.template_id, gt.course_code, gt.course_title, gt.course_unit, gt.class_code,
               ec.enrollment_id, ec.target_grade, gt.created_at
        FROM grade_templates gt
        JOIN enrolled_courses ec ON gt.template_id = ec.template_id
        WHERE ec.user_id = ?
        ORDER BY gt.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function enrollmentFindForUser(PDO $conn, int $enrollmentId, int $userId): ?array {
    $stmt = $conn->prepare("
        SELECT ec.enrollment_id, ec.target_grade, gt.template_id, gt.course_code, gt.course_title, gt.course_unit
        FROM enrolled_courses ec
        JOIN grade_templates gt ON gt.template_id = ec.template_id
        WHERE ec.enrollment_id = ? AND ec.user_id = ?
    ");
    $stmt->execute([$enrollmentId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function enrollmentCreate(PDO $conn, int $userId, int $templateId): ?int {
    $stmt = $conn->prepare("
        INSERT INTO enrolled_courses (user_id, template_id)
        VALUES (?, ?)
        ON CONFLICT (user_id, template_id) DO NOTHING
        RETURNING enrollment_id
    ");
    $stmt->execute([$userId, $templateId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function enrollmentDeleteForUser(PDO $conn, int $enrollmentId, int $userId): bool {
    $stmt = $conn->prepare("DELETE FROM enrolled_courses WHERE enrollment_id = ? AND user_id = ?");
    $stmt->execute([$enrollmentId, $userId]);
    return $stmt->rowCount() === 1;
}

function enrollmentUpdateTarget(PDO $conn, int $enrollmentId, int $userId, float $target): bool {
    $stmt = $conn->prepare("UPDATE enrolled_courses SET target_grade = ? WHERE enrollment_id = ? AND user_id = ?");
    $stmt->execute([$target, $enrollmentId, $userId]);
    return $stmt->rowCount() === 1;
}

function scoreDeleteForEnrollment(PDO $conn, int $enrollmentId, int $userId): void {
    $stmt = $conn->prepare("DELETE FROM student_component_scores WHERE enrollment_id = ?
        AND enrollment_id IN (SELECT enrollment_id FROM enrolled_courses WHERE user_id = ?)");
    $stmt->execute([$enrollmentId, $userId]);
}

function scoreInsertStatement(PDO $conn, string $period): PDOStatement {
    $columns = ['midterms' => 'midterm_component_id', 'finals' => 'final_component_id'];
    $tables = componentTables();
    $column = $columns[$period];
    $table = $tables[$period];

    return $conn->prepare("INSERT INTO student_component_scores
        (enrollment_id, {$column}, assessment_title, raw_score, total_items)
        SELECT ec.enrollment_id, tc.id, ?, ?, ? FROM enrolled_courses ec
        JOIN {$table} tc ON tc.template_id = ec.template_id
        WHERE ec.enrollment_id = ? AND ec.user_id = ? AND tc.id = ?");
}

/* =========================================================================
   2. Student Core Logic (Dashboard, Analytics, Score Saving)
   ========================================================================= */

function studentDashboard(int $userId): array {
    $conn = db();
    $courses = [];

    foreach (enrollmentListByUser($conn, $userId) as $row) {
        $templateId = (int)$row['template_id'];
        $components = loadTemplateComponents($conn, $templateId);
        $scores = loadEnrollmentScores($conn, (int)$row['enrollment_id'], $templateId);
        $result = calculateCourseGrade($components, $scores);
        $performance = summarizeComponentPerformance($components, $scores);

        $courses[] = [
            'id' => $templateId,
            'templateId' => $templateId,
            'enrollmentId' => (int)($row['enrollment_id'] ?? 0),
            'code' => $row['course_code'],
            'title' => $row['course_title'],
            'units' => (float)$row['course_unit'],
            'classCode' => $row['class_code'],
            'components' => $components,
            'componentResults' => $performance['components'],
            'gradedWeight' => $performance['gradedWeight'],
            'grade' => $result['grade'],
            'percentage' => $result['percentage'],
            'gradeStatus' => $result['status'],
            'periods' => $result['periods'],
            'target' => (float)$row['target_grade'],
        ];
    }

    return $courses;
}

function studentDashboardAnalytics(array $courses): array {
    $finalizedSubjects = 0;
    $atOrWithinTarget = 0;
    $gradedUnits = 0.0;
    $weightedGrades = 0.0;

    foreach ($courses as $course) {
        if (!is_numeric($course['grade'] ?? null)) continue;

        $grade = (float)$course['grade'];
        $units = (float)$course['units'];
        $finalizedSubjects++;
        $gradedUnits += $units;
        $weightedGrades += $grade * $units;

        if ($grade <= (float)$course['target']) $atOrWithinTarget++;
    }

    return [
        'currentGwa' => $gradedUnits > 0 ? round($weightedGrades / $gradedUnits, 2) : null,
        'finalizedSubjects' => $finalizedSubjects,
        'totalSubjects' => count($courses),
        'atOrWithinTarget' => $atOrWithinTarget,
    ];
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

    $courses = studentDashboard($userId);
    return [
        'targetGrade' => $target,
        'analytics' => studentDashboardAnalytics($courses),
        'redirect' => 'student_dashboard.html?target=saved#grades',
    ];
}

/* =========================================================================
   3. Route Handlers (Dispatched directly by router)
   ========================================================================= */

function studentDashboardController(): void {
    $user = requireStudent();
    $courses = studentDashboard($user['userId']);
    jsonSuccess([
        'user' => $user,
        'courses' => $courses,
        'analytics' => studentDashboardAnalytics($courses),
        'gradeScale' => array_column(pupGradeScale(), 1),
        'gradeScalePairs' => pupGradeScale(),
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