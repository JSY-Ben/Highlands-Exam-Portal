<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_POST['exam_id'] ?? 0);
$studentName = trim((string) ($_POST['student_name'] ?? ''));
$candidateNumber = trim((string) ($_POST['candidate_number'] ?? ''));
$confirmed = isset($_POST['confirm_final']);

if ($examId <= 0 || $studentName === '' || $candidateNumber === '' || !$confirmed) {
    http_response_code(422);
    echo 'Missing required fields.';
    exit;
}

$now = new DateTimeImmutable('now');

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || !exam_is_active($exam, $now)) {
    http_response_code(403);
    echo 'Exam not accepting submissions.';
    exit;
}

$stmt = db()->prepare('SELECT * FROM exam_documents WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$documents = $stmt->fetchAll();

if (count($documents) === 0) {
    http_response_code(400);
    echo 'No documents configured for this exam.';
    exit;
}

$uploadedCount = 0;
foreach ($documents as $doc) {
    $key = 'file_' . $doc['id'];
    if (!isset($_FILES[$key])) {
        continue;
    }

    $error = $_FILES[$key]['error'];
    if ($error === UPLOAD_ERR_OK) {
        $uploadedCount++;
        continue;
    }

    if ($error !== UPLOAD_ERR_NO_FILE) {
        http_response_code(422);
        echo 'Problem uploading file: ' . e($doc['title']);
        exit;
    }
}

if ($uploadedCount === 0) {
    http_response_code(422);
    echo 'Please upload at least one file.';
    exit;
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare('INSERT INTO submissions (exam_id, student_name, candidate_number, submitted_at, ip_address) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $examId,
        $studentName,
        $candidateNumber,
        now_utc_string(),
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    ]);

    $submissionId = (int) $pdo->lastInsertId();
    $examFolder = $uploadsDir . '/exam_' . $examId;
    $submissionFolder = $examFolder . '/submission_' . $submissionId;

    if (!is_dir($submissionFolder) && !mkdir($submissionFolder, 0755, true)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $insertFile = $pdo->prepare(
        'INSERT INTO submission_files (submission_id, exam_document_id, original_name, stored_name, stored_path, file_size) VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($documents as $doc) {
        $key = 'file_' . $doc['id'];
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            continue;
        }

        $file = $_FILES[$key];
        $originalName = $file['name'];
        $examIdentifier = $exam['exam_code'] ?? $exam['id'];
        $baseName = apply_name_template(
            $exam['file_name_template'] ?? '',
            [
                'exam_id' => $examIdentifier,
                'exam_title' => $exam['title'],
                'student_name' => $studentName,
                'candidate_number' => $candidateNumber,
                'document_title' => $doc['title'],
                'original_name' => $originalName,
                'submission_id' => (string) $submissionId,
            ],
            $originalName
        );
        $baseName = ensure_original_extension($baseName, $originalName);
        $baseName = sanitize_name_component($baseName);
        $storedName = uniqid('file_', true) . '_' . $baseName;
        $storedPath = $submissionFolder . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            throw new RuntimeException('Failed to store upload.');
        }

        $relativePath = str_replace($uploadsDir . '/', '', $storedPath);

        $insertFile->execute([
            $submissionId,
            $doc['id'],
            $originalName,
            $storedName,
            $relativePath,
            (int) $file['size'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();

    if (isset($submissionFolder) && is_dir($submissionFolder)) {
        $files = glob($submissionFolder . '/*');
        if (is_array($files)) {
            foreach ($files as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        rmdir($submissionFolder);
    }

    http_response_code(500);
    echo 'Upload failed. Please try again.';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Submission Received</title>
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">Exam Submission Portal</a>
    </div>
</nav>

<main class="container py-5">
    <div class="alert alert-success shadow-sm">
        <h1 class="h4 mb-2">Submission received</h1>
        <p class="mb-0">Your files have been submitted successfully.</p>
    </div>
</main>
</body>
</html>
