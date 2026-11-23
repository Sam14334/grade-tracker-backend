<?php

declare(strict_types=1);

/* =========================================================================
   1. PUP Grade Scales & Grading Periods
   ========================================================================= */

function pupGradeScale(): array {
    return [
        [97, 1.00], [94, 1.25], [91, 1.50], [88, 1.75], [85, 2.00],
        [82, 2.25], [79, 2.50], [76, 2.75], [75, 3.00], [0, 5.00],
    ];
}

function allowedTargetGrades(): array {
    return array_values(array_filter(array_column(pupGradeScale(), 1), fn($grade) => $grade <= 3.00));
}

function gradingPeriods(): array {
    return ['midterms' => 'Midterms', 'finals' => 'Finals'];
}

function componentTables(): array {
    return [
        'midterms' => 'midterms_template_components',
        'finals' => 'finals_template_components',
    ];
}

function percentageToGrade(float $percentage): float {
    $percentage = round($percentage, 10);
    foreach (pupGradeScale() as [$minimum, $grade]) {
        if ($percentage >= $minimum) return $grade;
    }
    return 5.00;
}

/* =========================================================================
   2. Grade Calculation Formulas
   ========================================================================= */

function calculatePeriodGrade(array $components, array $scores): array {
    $totals = [];
    foreach ($scores as $score) {
        $id = (string)$score['component_id'];
        if (!isset($totals[$id])) $totals[$id] = ['score' => 0, 'max' => 0];
        $totals[$id]['score'] += (float)$score['raw_score'];
        $totals[$id]['max'] += (int)$score['total_items'];
    }

    $weightTotal = 0;
    $percentage = 0.0;
    $pendingExams = false;
    $missingCoursework = false;
    $validWeights = count($components) > 0;

    foreach ($components as $component) {
        $weight = (int)$component['weight'];
        $weightTotal += $weight;
        if ($weight < 0 || $weight > 100) $validWeights = false;

        $total = $totals[(string)$component['id']] ?? ['score' => 0, 'max' => 0];
        if ($total['max'] <= 0) {
            if (filter_var($component['is_exam'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $pendingExams = true;
            } else {
                $missingCoursework = true;
            }
        } else {
            $percentage += ($total['score'] / $total['max']) * $weight;
        }
    }

    if (!$validWeights || $weightTotal !== 100) {
        return ['grade' => null, 'percentage' => null, 'status' => 'Invalid weights'];
    }
    if ($missingCoursework) {
        return ['grade' => null, 'percentage' => null, 'status' => 'Incomplete'];
    }
    if ($pendingExams) {
        return ['grade' => null, 'percentage' => $percentage, 'status' => 'In Progress'];
    }
    return ['grade' => percentageToGrade($percentage), 'percentage' => $percentage, 'status' => 'Complete'];
}

function calculateCourseGrade(array $components, array $scores): array {
    $periods = [];
    foreach (gradingPeriods() as $period => $label) {
        $periodComponents = array_values(array_filter($components, fn($component) => $component['period'] === $period));
        $periods[$period] = calculatePeriodGrade($periodComponents, $scores);
    }
    $statuses = array_column($periods, 'status');
    $status = 'Complete';
    foreach (['Invalid weights', 'Incomplete', 'In Progress'] as $pendingStatus) {
        if (in_array($pendingStatus, $statuses, true)) {
            $status = $pendingStatus;
            break;
        }
    }
    $midterms = $periods['midterms']['percentage'];
    $finals = $periods['finals']['percentage'];
    $percentage = $midterms !== null && $finals !== null ? ($midterms + $finals) / 2 : null;
    return [
        'grade' => $status === 'Complete' ? percentageToGrade($percentage) : null,
        'percentage' => $percentage,
        'status' => $status,
        'periods' => $periods,
    ];
}

function summarizeComponentPerformance(array $components, array $scores): array {
    $totals = [];
    foreach ($scores as $score) {
        $key = (string)$score['component_id'];
        if (!isset($totals[$key])) {
            $totals[$key] = ['score' => 0.0, 'max' => 0, 'assessments' => []];
        }
        $totals[$key]['score'] += (float)$score['raw_score'];
        $totals[$key]['max'] += (int)$score['total_items'];
        $totals[$key]['assessments'][] = [
            'title' => (string)$score['assessment_title'],
            'score' => (float)$score['raw_score'],
            'max' => (int)$score['total_items'],
        ];
    }

    $results = [];
    $gradedWeight = 0.0;
    $earnedPoints = 0.0;
    foreach ($components as $component) {
        $total = $totals[(string)$component['id']] ?? ['score' => 0.0, 'max' => 0, 'assessments' => []];
        $courseWeight = (int)$component['weight'] / 2;
        if ($total['max'] > 0) {
            $gradedWeight += $courseWeight;
            $earnedPoints += ($total['score'] / $total['max']) * $courseWeight;
        }

        $results[] = [
            'id' => $component['id'],
            'period' => $component['period'],
            'name' => $component['name'],
            'weight' => (int)$component['weight'],
            'is_exam' => (bool)$component['is_exam'],
            'percentage' => $total['max'] > 0 ? $total['score'] / $total['max'] * 100 : null,
            'score' => $total['score'],
            'max' => $total['max'],
            'assessments' => $total['assessments'],
        ];
    }

    return [
        'components' => $results,
        'gradedWeight' => $gradedWeight,
        'earnedPoints' => $earnedPoints,
        'percentage' => $gradedWeight > 0 ? round($earnedPoints / $gradedWeight * 100, 10) : null,
    ];
}

/* =========================================================================
   3. Database Queries for Components & Scores
   ========================================================================= */

function loadTemplateComponents(PDO $conn, int $templateId): array {
    $components = [];
    foreach (componentTables() as $period => $table) {
        $stmt = $conn->prepare("SELECT id, component_name, weight, is_exam FROM {$table} WHERE template_id = ? ORDER BY id");
        $stmt->execute([$templateId]);
        while ($row = $stmt->fetch()) {
            $components[] = [
                'id' => $period . '_' . $row['id'],
                'database_id' => (int)$row['id'],
                'period' => $period,
                'name' => $row['component_name'],
                'weight' => (int)$row['weight'],
                'is_exam' => filter_var($row['is_exam'], FILTER_VALIDATE_BOOLEAN),
            ];
        }
    }
    return $components;
}

function loadUsedTemplateComponentIds(PDO $conn, int $templateId, string $period): array {
    $tables = componentTables();
    $columns = ['midterms' => 'midterm_component_id', 'finals' => 'final_component_id'];
    if (!isset($tables[$period])) throw new InvalidArgumentException('Invalid grading period.');
    $table = $tables[$period];
    $column = $columns[$period];

    $stmt = $conn->prepare("SELECT tc.id FROM {$table} tc WHERE tc.template_id = ?
        AND EXISTS(SELECT 1 FROM student_component_scores s WHERE s.{$column} = tc.id)");
    $stmt->execute([$templateId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function loadEnrollmentScores(PDO $conn, int $enrollmentId, int $templateId): array {
    $scores = [];
    $columns = ['midterms' => 'midterm_component_id', 'finals' => 'final_component_id'];
    foreach (componentTables() as $period => $table) {
        $column = $columns[$period];
        $stmt = $conn->prepare("SELECT scs.*, tc.id AS period_component_id FROM student_component_scores scs
            JOIN {$table} tc ON tc.id = scs.{$column}
            WHERE scs.enrollment_id = ? AND tc.template_id = ? ORDER BY scs.score_id");
        $stmt->execute([$enrollmentId, $templateId]);
        while ($row = $stmt->fetch()) {
            $row['component_id'] = $period . '_' . $row['period_component_id'];
            $row['period'] = $period;
            $scores[] = $row;
        }
    }
    return $scores;
}