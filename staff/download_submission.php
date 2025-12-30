<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$submissionId = (int) ($_GET['submission_id'] ?? 0);

if ($submissionId <= 0) {
    http_response_code(400);
    echo 'Invalid submission.';
    exit;
}

$stmt = db()->prepare(
    'SELECT s.id AS submission_id, s.student_name, s.candidate_number, e.title AS exam_title,
            sf.original_name, sf.stored_path
     FROM submissions s
     JOIN exams e ON e.id = s.exam_id
     JOIN submission_files sf ON sf.submission_id = s.id
     WHERE s.id = ?
     ORDER BY sf.id ASC'
);
$stmt->execute([$submissionId]);
$files = $stmt->fetchAll();

if (count($files) === 0) {
    http_response_code(404);
    echo 'No files found for this submission.';
    exit;
}

$zip = new ZipArchive();
$tmpName = tempnam(sys_get_temp_dir(), 'submission_');
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

    $zip->addFile($realPath, basename($file['original_name']));
}

$zip->close();

$baseName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $files[0]['exam_title']);
$studentName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $files[0]['student_name']);
$candidate = preg_replace('/[^A-Za-z0-9_-]+/', '_', $files[0]['candidate_number']);
$downloadName = sprintf('%s_%s_%s.zip', $baseName, $studentName, $candidate);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);
unlink($zipPath);
exit;
