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
$brandHref = 'index.php';
$brandText = 'Exams Administration Portal';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '<button class="btn btn-outline-primary btn-sm no-print" type="button" onclick="window.print()">Print / Save as PDF</button>'
    . '<a class="btn btn-outline-secondary btn-sm no-print" href="exam_students.php?id=' . (int) $exam['id'] . '">Back to roster</a>';
$pageScripts = <<<HTML
<style>
@page {
    size: A4;
    margin: 14mm 16mm;
}
@media print {
    .no-print,
    nav,
    footer {
        display: none !important;
    }
    body {
        background: #fff;
    }
    main.container {
        max-width: none;
        width: 100%;
        padding: 0;
    }
    .row.row-cols-1.row-cols-md-2.row-cols-lg-3 {
        display: grid !important;
        grid-template-columns: 1fr;
        gap: 6mm;
        margin: 0;
        align-items: start;
        break-after: avoid-page;
        page-break-after: avoid;
        padding-bottom: 0;
    }
    .row.row-cols-1.row-cols-md-2.row-cols-lg-3 > .col {
        padding: 0;
        margin: 0;
        width: auto;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .student-card {
        break-inside: avoid;
        page-break-inside: avoid;
        min-height: 0;
        height: 62mm;
        box-sizing: border-box;
    }

    .row.row-cols-1.row-cols-md-2.row-cols-lg-3 > .col:last-child {
        margin-bottom: 0;
        padding-bottom: 0;
        break-after: avoid-page;
        page-break-after: avoid;
    }
}
.student-card .label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6c757d;
}
.student-card .value {
    font-weight: 600;
    font-size: 1.05rem;
}
.student-card .password {
    font-size: 1.35rem;
    letter-spacing: 0.2em;
    font-family: "Courier New", Courier, monospace;
}
</style>
HTML;
require __DIR__ . '/../header.php';
?>
<main class="container py-4">
    <div class="no-print">
        <h1 class="h4 mb-1"><?php echo e($exam['title']); ?> - Student Cards</h1>
        <button class="btn btn-primary btn-sm mt-2" type="button" onclick="window.print()">Print / Save as PDF</button>
        <p class="text-muted mt-2 mb-0">Print or save as PDF to distribute individual student access cards.</p>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mt-2">
        <?php foreach ($students as $student): ?>
            <div class="col">
                <div class="card shadow-sm h-100 student-card">
                    <div class="card-body">
                        <div class="label mb-1">Student</div>
                        <h2 class="h5 mb-3"><?php echo e(trim($student['student_first_name'] . ' ' . $student['student_last_name'])); ?></h2>
                        <div class="label mb-1">Candidate Number</div>
                        <div class="value mb-3"><?php echo e($student['candidate_number']); ?></div>
                        <div class="label mb-1">Submission Password</div>
                        <div class="value password"><?php echo e((string) ($student['access_password'] ?? '')); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>
<?php require __DIR__ . '/../footer.php'; ?>
