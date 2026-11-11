<?php

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
    $stmt = $conn->prepare("SELECT id FROM $table WHERE template_id = ? ORDER BY id FOR UPDATE");
    $stmt->execute([$templateId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function templateClearExams(PDO $conn, string $table, int $templateId): void {
    $stmt = $conn->prepare("UPDATE $table SET is_exam = FALSE WHERE template_id = ?");
    $stmt->execute([$templateId]);
}

function templateInsertComponent(PDO $conn, string $table, int $templateId, string $name, int $weight, bool $isExam): void {
    $stmt = $conn->prepare("INSERT INTO $table (template_id, component_name, weight, is_exam) VALUES (?, ?, ?, ?)");
    $stmt->execute([$templateId, $name, $weight, $isExam ? 'true' : 'false']);
}

function templateUpdateComponent(PDO $conn, string $table, int $componentId, int $templateId, string $name, int $weight, bool $isExam): void {
    $stmt = $conn->prepare("UPDATE $table SET component_name = ?, weight = ?, is_exam = ? WHERE id = ? AND template_id = ?");
    $stmt->execute([$name, $weight, $isExam ? 'true' : 'false', $componentId, $templateId]);
}

function templateDeleteComponent(PDO $conn, string $table, int $componentId, int $templateId): void {
    $stmt = $conn->prepare("DELETE FROM $table WHERE id = ? AND template_id = ?");
    $stmt->execute([$componentId, $templateId]);
}
