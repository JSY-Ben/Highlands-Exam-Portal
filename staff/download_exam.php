<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_GET['exam_id'] ?? 0);

if ($examId <= 0) {
    http_response_code(400);
    echo 'Invalid exam.';
    exit;
}

$stmt = db()->prepare(
    'SELECT e.title AS exam_title, s.id AS submission_id, s.student_name, s.candidate_number,
            sf.original_name, sf.stored_path
     FROM exams e
     JOIN submissions s ON s.exam_id = e.id
     JOIN submission_files sf ON sf.submission_id = s.id
     WHERE e.id = ?
     ORDER BY s.submitted_at DESC, sf.id ASC'
);
$stmt->execute([$examId]);
$files = $stmt->fetchAll();

if (count($files) === 0) {
    http_response_code(404);
    echo 'No files found for this exam.';
    exit;
}

$zip = new ZipArchive();
$tmpName = tempnam(sys_get_temp_dir(), 'exam_');
$zipPath = $tmpName . '.zip';
rename($tmpName, $zipPath);

if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    http_response_code(500);
    echo 'Unable to create archive.';
    exit;
}

foreach ($files as $file) {
    $path = $uploadsDir . '/' . ltrim($file['stored_path'], '/');
    $realPath = realpath($path);
    if (!$realPath || strpos($realPath, realpath($uploadsDir)) !== 0) {
        continue;
    }
    if (!is_file($realPath)) {
        continue;
    }

    $studentName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $file['student_name']);
    $candidate = preg_replace('/[^A-Za-z0-9_-]+/', '_', $file['candidate_number']);
    $folder = sprintf('%s_%s', $studentName, $candidate);

    $zip->addFile($realPath, $folder . '/' . basename($file['original_name']));
}

$zip->close();

$baseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $files[0]['exam_title']);
$downloadName = sprintf('%s_all_submissions.zip', $baseName);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);
unlink($zipPath);
exit;
