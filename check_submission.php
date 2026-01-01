<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

header('Content-Type: application/json');

$examId = (int) ($_GET['exam_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);

if ($examId <= 0 || $studentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || empty($exam['student_roster_enabled'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Roster not enabled.']);
    exit;
}

$stmt = db()->prepare('SELECT * FROM exam_students WHERE id = ? AND exam_id = ?');
$stmt->execute([$studentId, $examId]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    echo json_encode(['error' => 'Student not found.']);
    exit;
}

$stmt = db()->prepare(
    'SELECT COUNT(*) FROM submissions
     WHERE exam_id = ?
       AND TRIM(candidate_number) = ?
       AND TRIM(student_first_name) = ?
       AND TRIM(student_last_name) = ?'
);
$stmt->execute([
    $examId,
    trim((string) $student['candidate_number']),
    trim((string) $student['student_first_name']),
    trim((string) $student['student_last_name']),
]);

echo json_encode(['has_submission' => ((int) $stmt->fetchColumn()) > 0]);
