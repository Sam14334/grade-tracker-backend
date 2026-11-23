<?php

declare(strict_types=1);

// template input and validation
function normalizeTemplateComponents(mixed $components): array {
    $normalized = [];
    foreach (gradingPeriods() as $period => $label) {
        $rows = is_array($components) && is_array($components[$period] ?? null) ? $components[$period] : [];
        $normalized[$period] = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $normalized[$period][] = $row;
                continue;
            }
            $normalized[$period][] = [
                'id' => is_scalar($row['id'] ?? null) ? (string)$row['id'] : '',
                'name' => is_scalar($row['name'] ?? null) ? (string)$row['name'] : '',
                'weight' => is_scalar($row['weight'] ?? null) ? (string)$row['weight'] : '',
                'is_exam' => !empty($row['is_exam']) ? '1' : '0',
            ];
        }
    }
    return $normalized;
}

function templateFormInput(array $input): array {
    $text = fn($value) => is_string($value) ? trim($value) : '';
    $form = [
        'courseCode' => $text($input['courseCode'] ?? ''),
        'courseTitle' => $text($input['courseTitle'] ?? ''),
        'courseUnit' => $text($input['courseUnit'] ?? ''),
        'components' => [],
    ];
    foreach (gradingPeriods() as $period => $label) {
        $rows = $input['components'][$period] ?? [];
        $form['components'][$period] = [];
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            $form['components'][$period][] = [
                'id' => $text(is_array($row) ? ($row['id'] ?? '') : ''),
                'name' => $text(is_array($row) ? ($row['name'] ?? '') : ''),
                'weight' => $text(is_array($row) ? ($row['weight'] ?? '') : ''),
                'is_exam' => is_array($row) && ($row['is_exam'] ?? '') === '1',
                'invalid' => !is_array($row) || (isset($row['id']) && !is_string($row['id'])),
            ];
        }
    }
    return $form;
}

function templateFormError(array $form): string {
    $titleLength = preg_match_all('/./us', $form['courseTitle']);
    if ($form['courseCode'] === '' || strlen($form['courseCode']) > 20 ||
        $form['courseTitle'] === '' || $titleLength === false || $titleLength > 100) {
        return 'Enter a course code (up to 20 characters) and title (up to 100 characters).';
    }
    $units = filter_var($form['courseUnit'], FILTER_VALIDATE_FLOAT);
    if ($units === false || $units <= 0 || $units > 99.9 || round($units, 1) !== $units) {
        return 'Enter a positive unit value up to 99.9, with at most one decimal place.';
    }
    foreach (gradingPeriods() as $period => $label) {
        $rows = $form['components'][$period];
        if (!$rows) return "{$label} needs components totaling 100%.";
        $weightTotal = 0;
        $examCount = 0;
        $ids = [];
        foreach ($rows as $row) {
            $length = preg_match_all('/./us', $row['name']);
            $weight = filter_var($row['weight'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($row['invalid'] || $row['name'] === '' || $length === false || $length > 100 || $weight === false) {
                return "{$label} needs component names of up to 100 characters and whole-number weights from 1 to 100.";
            }
            if ($row['id'] !== '') {
                $id = filter_var($row['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || isset($ids[$id])) return "{$label} contains an invalid or repeated component.";
                $ids[$id] = true;
            }
            $weightTotal += $weight;
            $examCount += $row['is_exam'] ? 1 : 0;
        }
        if ($weightTotal !== 100 || $examCount > 1) return "{$label} must total 100% and have no more than one checked exam.";
    }
    return '';
}

// db queries
function templateListByUser(PDO $conn, int $userId): array {
    $stmt = $conn->prepare("
        SELECT template_id, course_code, course_title, course_unit, class_code
        FROM grade_templates
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function templateFindOwned(PDO $conn, int $templateId, int $userId): ?array {
    $stmt = $conn->prepare("
        SELECT template_id, course_code, course_title, course_unit, class_code
        FROM grade_templates
        WHERE template_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$templateId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function templateFindByClassCode(PDO $conn, string $classCode): ?array {
    $stmt = $conn->prepare("SELECT template_id, course_code, course_title FROM grade_templates WHERE class_code = ? LIMIT 1");
    $stmt->execute([$classCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function templateLockOwned(PDO $conn, int $templateId, int $userId): ?array {
    $stmt = $conn->prepare("SELECT template_id FROM grade_templates WHERE template_id = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$templateId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function templateCreate(PDO $conn, int $userId, string $courseCode, string $courseTitle, string $courseUnit, string $classCode): ?int {
    $stmt = $conn->prepare("
        INSERT INTO grade_templates (user_id, course_code, course_title, class_code, course_unit)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (class_code) DO NOTHING
        RETURNING template_id
    ");
    $stmt->execute([$userId, $courseCode, $courseTitle, $classCode, $courseUnit]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function templateUpdateDetails(PDO $conn, int $templateId, int $userId, string $courseCode, string $courseTitle, string $courseUnit): void {
    $stmt = $conn->prepare("
        UPDATE grade_templates
        SET course_code = ?, course_title = ?, course_unit = ?
        WHERE template_id = ? AND user_id = ?
    ");
    $stmt->execute([$courseCode, $courseTitle, $courseUnit, $templateId, $userId]);
}

function templateDeleteOwned(PDO $conn, int $templateId, int $userId): bool {
    $stmt = $conn->prepare("DELETE FROM grade_templates WHERE template_id = ? AND user_id = ?");
    $stmt->execute([$templateId, $userId]);
    return $stmt->rowCount() === 1;
}

function templateEnrollOwner(PDO $conn, int $userId, int $templateId): void {
    $stmt = $conn->prepare("INSERT INTO enrolled_courses (user_id, template_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
    $stmt->execute([$userId, $templateId]);
}

function templateExistingComponentIds(PDO $conn, string $table, int $templateId): array {
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE template_id = ? ORDER BY id FOR UPDATE");
    $stmt->execute([$templateId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function templateClearExams(PDO $conn, string $table, int $templateId): void {
    $stmt = $conn->prepare("UPDATE {$table} SET is_exam = FALSE WHERE template_id = ?");
    $stmt->execute([$templateId]);
}

function templateInsertComponent(PDO $conn, string $table, int $templateId, string $name, int $weight, bool $isExam): void {
    $stmt = $conn->prepare("INSERT INTO {$table} (template_id, component_name, weight, is_exam) VALUES (?, ?, ?, ?)");
    $stmt->execute([$templateId, $name, $weight, $isExam ? 'true' : 'false']);
}

function templateUpdateComponent(PDO $conn, string $table, int $componentId, int $templateId, string $name, int $weight, bool $isExam): void {
    $stmt = $conn->prepare("UPDATE {$table} SET component_name = ?, weight = ?, is_exam = ? WHERE id = ? AND template_id = ?");
    $stmt->execute([$name, $weight, $isExam ? 'true' : 'false', $componentId, $templateId]);
}

function templateDeleteComponent(PDO $conn, string $table, int $componentId, int $templateId): void {
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ? AND template_id = ?");
    $stmt->execute([$componentId, $templateId]);
}

// template logic
function templateSave(array $input, ?int $templateId, int $userId): array {
    $form = templateFormInput([
        'courseCode' => is_scalar($input['courseCode'] ?? null) ? (string)$input['courseCode'] : '',
        'courseTitle' => is_scalar($input['courseTitle'] ?? null) ? (string)$input['courseTitle'] : '',
        'courseUnit' => is_scalar($input['courseUnit'] ?? null) ? (string)$input['courseUnit'] : '',
        'components' => normalizeTemplateComponents($input['components'] ?? []),
    ]);

    $error = templateFormError($form);
    if ($error !== '') throw new ApiException($error, 422);

    $conn = db();
    $isEditing = $templateId !== null && $templateId > 0;
    $classCode = '';

    try {
        $conn->beginTransaction();

        if ($isEditing) {
            if (!templateLockOwned($conn, $templateId, $userId)) {
                throw new DomainException('This template is no longer available to edit.');
            }
            templateUpdateDetails($conn, $templateId, $userId, $form['courseCode'], $form['courseTitle'], $form['courseUnit']);
        } else {
            $codePrefix = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $form['courseCode'])) . '-SEC';
            $createdId = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $classCode = $codePrefix . random_int(100000, 999999);
                $newId = templateCreate($conn, $userId, $form['courseCode'], $form['courseTitle'], $form['courseUnit'], $classCode);
                if ($newId !== null) {
                    $createdId = $newId;
                    break;
                }
            }
            if ($createdId === 0) {
                throw new DomainException('Could not generate an available enrollment code. Please try publishing again.');
            }
            $templateId = $createdId;
        }

        foreach (componentTables() as $period => $table) {
            $existing = [];
            if ($isEditing) {
                $existing = array_fill_keys(templateExistingComponentIds($conn, $table, $templateId), true);
            }

            $retained = [];
            foreach ($form['components'][$period] as $component) {
                if ($component['id'] === '') continue;
                $id = (int)$component['id'];
                if (!isset($existing[$id])) {
                    throw new DomainException('A component does not belong to this template or grading period. Reload and try again.');
                }
                $retained[$id] = true;
            }

            $removed = array_diff_key($existing, $retained);
            $usedIds = $removed ? array_fill_keys(loadUsedTemplateComponentIds($conn, $templateId, $period), true) : [];
            foreach ($removed as $id => $_) {
                if (isset($usedIds[$id])) {
                    throw new DomainException('A removed component has saved student scores. Keep that component; you can still edit its name, weight, and exam setting.');
                }
                templateDeleteComponent($conn, $table, (int)$id, $templateId);
            }

            if ($isEditing) templateClearExams($conn, $table, $templateId);

            foreach ($form['components'][$period] as $component) {
                $weight = (int)$component['weight'];
                if ($component['id'] !== '') {
                    templateUpdateComponent($conn, $table, (int)$component['id'], $templateId, $component['name'], $weight, $component['is_exam']);
                } else {
                    templateInsertComponent($conn, $table, $templateId, $component['name'], $weight, $component['is_exam']);
                }
            }
        }

        if (!$isEditing) templateEnrollOwner($conn, $userId, $templateId);

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if ($e instanceof ApiException) throw $e;
        if ($e instanceof DomainException) throw new ApiException($e->getMessage(), 422);
        error_log('Template save failed: ' . $e->getMessage());
        throw new ApiException('Could not save the template. Your changes have not been applied. Please try again.', 500);
    }

    return ['templateId' => $templateId, 'classCode' => $classCode];
}

// router handler
function templateListController(): void {
    $user = requireProfessor();
    $conn = db();
    $templates = [];
    foreach (templateListByUser($conn, $user['userId']) as $row) {
        $templates[] = [
            'id' => (int)$row['template_id'],
            'code' => $row['course_code'],
            'title' => $row['course_title'],
            'classCode' => $row['class_code'],
            'units' => (float)$row['course_unit'],
            'components' => loadTemplateComponents($conn, (int)$row['template_id']),
        ];
    }
    jsonSuccess(['templates' => $templates]);
}

function templateShowController(array $params): void {
    $user = requireProfessor();
    $conn = db();
    $templateId = (int)$params['id'];
    $row = templateFindOwned($conn, $templateId, $user['userId']);
    if (!$row) throw new ApiException('Template not found in your published courses.', 404);

    $lockedComponents = [];
    foreach (componentTables() as $period => $table) {
        $lockedComponents[$period] = loadUsedTemplateComponentIds($conn, $templateId, $period);
    }

    jsonSuccess([
        'template' => [
            'id' => (int)$row['template_id'],
            'code' => $row['course_code'],
            'title' => $row['course_title'],
            'classCode' => $row['class_code'],
            'units' => (float)$row['course_unit'],
            'components' => loadTemplateComponents($conn, $templateId),
        ],
        'lockedComponents' => $lockedComponents,
    ]);
}

function templateCreateController(): void {
    $user = requireProfessor();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(templateSave($data, null, $user['userId']));
}

function templateUpdateController(array $params): void {
    $user = requireProfessor();
    $data = requestData();
    requireCsrf($data);
    jsonSuccess(templateSave($data, (int)$params['id'], $user['userId']));
}

function templateDeleteController(array $params): void {
    $user = requireProfessor();
    requireCsrf(requestData());
    $conn = db();
    if (!templateDeleteOwned($conn, (int)$params['id'], $user['userId'])) {
        throw new ApiException('Template not found in your published courses.', 404);
    }
    jsonSuccess();
}