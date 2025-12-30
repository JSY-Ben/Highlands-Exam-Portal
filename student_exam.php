<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$examId = (int) ($_GET['id'] ?? 0);
$now = new DateTimeImmutable('now');

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || !exam_is_active($exam, $now)) {
    http_response_code(404);
    echo 'Exam not available.';
    exit;
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Submit Files - <?php echo e($exam['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="/index.php">Exam Submission Portal</a>
    </div>
</nav>

<main class="container py-4">
    <div class="mb-4">
        <h1 class="h3">Submit for <?php echo e($exam['title']); ?></h1>
        <p class="text-muted">Upload all required files before the submission window ends.</p>
    </div>

    <form class="card shadow-sm" action="/submit.php" method="post" enctype="multipart/form-data">
        <div class="card-body">
            <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Student Name</label>
                    <input class="form-control" type="text" name="student_name" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Candidate Number</label>
                    <input class="form-control" type="text" name="candidate_number" required>
                </div>
            </div>

            <div class="mb-3">
                <h2 class="h6">Required Documents</h2>
                <div class="row g-3">
                    <?php foreach ($documents as $doc): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo e($doc['title']); ?></label>
                            <input class="form-control" type="file" name="file_<?php echo (int) $doc['id']; ?>" required>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="confirm_final" value="1" required>
                <label class="form-check-label">I confirm this is my final submission.</label>
            </div>

            <button class="btn btn-primary" type="submit">Submit Files</button>
        </div>
    </form>
</main>
</body>
</html>
