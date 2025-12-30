<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$fileId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT sf.original_name, sf.stored_path, ed.title AS document_title, e.title AS exam_title,
            e.file_name_template, e.exam_code, e.id AS exam_id, s.student_name, s.candidate_number, s.id AS submission_id
     FROM submission_files sf
     JOIN submissions s ON s.id = sf.submission_id
     JOIN exams e ON e.id = s.exam_id
     JOIN exam_documents ed ON ed.id = sf.exam_document_id
     WHERE sf.id = ?'
);
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$path = $uploadsDir . '/' . ltrim($file['stored_path'], '/');
$realPath = realpath($path);

if (!$realPath || strpos($realPath, realpath($uploadsDir)) !== 0) {
    http_response_code(403);
    echo 'Invalid file path.';
    exit;
}

if (!is_file($realPath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$examIdentifier = $file['exam_code'] ?? $file['exam_id'] ?? '';
$filename = apply_name_template(
    $file['file_name_template'] ?? '',
    [
        'exam_id' => $examIdentifier,
        'exam_title' => $file['exam_title'],
        'student_name' => $file['student_name'],
        'candidate_number' => $file['candidate_number'],
        'document_title' => $file['document_title'],
        'original_name' => $file['original_name'],
        'submission_id' => (string) $file['submission_id'],
    ],
    $file['original_name']
);
$filename = ensure_original_extension($filename, $file['original_name']);
$filename = sanitize_name_component($filename);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realPath));

readfile($realPath);
exit;
