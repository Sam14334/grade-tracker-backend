<?php

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

function templateList(int $userId): array {
    $conn = db();
    $templates = [];
    foreach (templateListByUser($conn, $userId) as $row) {
        $templates[] = [
            'id' => (int)$row['template_id'],
            'code' => $row['course_code'],
            'title' => $row['course_title'],
            'classCode' => $row['class_code'],
            'units' => (float)$row['course_unit'],
            'components' => loadTemplateComponents($conn, (int)$row['template_id']),
        ];
    }
    return $templates;
}

function templateDetail(int $templateId, int $userId): array {
    $conn = db();
    $row = templateFindOwned($conn, $templateId, $userId);
    if (!$row) throw new ApiException('Template not found in your published courses.', 404);

    $lockedComponents = [];
    foreach (componentTables() as $period => $table) {
        $lockedComponents[$period] = loadUsedTemplateComponentIds($conn, $templateId, $period);
    }

    return [
        'template' => [
            'id' => (int)$row['template_id'],
            'code' => $row['course_code'],
            'title' => $row['course_title'],
            'classCode' => $row['class_code'],
            'units' => (float)$row['course_unit'],
            'components' => loadTemplateComponents($conn, $templateId),
        ],
        'lockedComponents' => $lockedComponents,
    ];
}

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
                // Keep component IDs stable so their saved scores stay attached.
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
                // Do not let the foreign-key cascade erase saved scores.
                if (isset($usedIds[$id])) {
                    throw new DomainException('A removed component has saved student scores. Keep that component; you can still edit its name, weight, and exam setting.');
                }
                templateDeleteComponent($conn, $table, (int)$id, $templateId);
            }

            // Clear the old exam first so switching it does not violate the one-exam index.
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

function templateDelete(int $templateId, int $userId): void {
    $conn = db();
    if (!templateDeleteOwned($conn, $templateId, $userId)) {
        throw new ApiException('Template not found in your published courses.', 404);
    }
}
