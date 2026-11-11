<?php

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
