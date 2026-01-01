<?php

declare(strict_types=1);

require __DIR__ . '/../auth/require_auth.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$examId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    echo 'Exam not found.';
    exit;
}

if (empty($exam['student_roster_enabled']) || ($exam['student_roster_mode'] ?? '') !== 'password') {
    http_response_code(400);
    echo 'Student roster passwords are not enabled for this exam.';
    exit;
}

$stmt = db()->prepare('SELECT * FROM exam_students WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$students = $stmt->fetchAll();

if (count($students) === 0) {
    http_response_code(400);
    echo 'No students found for this exam.';
    exit;
}

$pageTitle = 'Student Cards - ' . $exam['title'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?></title>
    <link rel="stylesheet" href="../style.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #fff;
            }
        }
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        .student-card {
            border: 1px solid #e1e5ea;
            border-radius: 12px;
            padding: 16px;
            background: #fff;
            min-height: 160px;
        }
        .student-card h2 {
            font-size: 1.05rem;
            margin: 0 0 8px;
        }
        .student-card .label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 2px;
        }
        .student-card .value {
            font-weight: 600;
            font-size: 1rem;
        }
        .student-card .password {
            font-size: 1.25rem;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-start gap-3 no-print">
            <div>
                <h1 class="h4 mb-1"><?php echo e($exam['title']); ?> - Student Cards</h1>
                <p class="text-muted mb-0">Print or save as PDF to distribute individual student access cards.</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print / Save as PDF</button>
                <a class="btn btn-outline-secondary btn-sm" href="exam_students.php?id=<?php echo (int) $exam['id']; ?>">Back to roster</a>
            </div>
        </div>

        <div class="card-grid mt-4">
            <?php foreach ($students as $student): ?>
                <div class="student-card">
                    <div class="label">Student</div>
                    <h2><?php echo e(trim($student['student_first_name'] . ' ' . $student['student_last_name'])); ?></h2>
                    <div class="label">Candidate Number</div>
                    <div class="value"><?php echo e($student['candidate_number']); ?></div>
                    <div class="label mt-2">Submission Password</div>
                    <div class="value password"><?php echo e((string) ($student['access_password'] ?? '')); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
