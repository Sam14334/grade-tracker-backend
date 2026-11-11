<?php

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
        (enrollment_id, $column, assessment_title, raw_score, total_items)
        SELECT ec.enrollment_id, tc.id, ?, ?, ? FROM enrolled_courses ec
        JOIN $table tc ON tc.template_id = ec.template_id
        WHERE ec.enrollment_id = ? AND ec.user_id = ? AND tc.id = ?");
}
