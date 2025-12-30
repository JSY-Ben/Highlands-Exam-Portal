<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

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
            e.file_name_template, e.folder_name_template, sf.original_name, sf.stored_path,
            ed.title AS document_title
     FROM submissions s
     JOIN exams e ON e.id = s.exam_id
     JOIN submission_files sf ON sf.submission_id = s.id
     JOIN exam_documents ed ON ed.id = sf.exam_document_id
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

    $fileName = apply_name_template(
        $file['file_name_template'] ?? '',
        [
            'exam_title' => $file['exam_title'],
            'student_name' => $file['student_name'],
            'candidate_number' => $file['candidate_number'],
            'document_title' => $file['document_title'],
            'original_name' => $file['original_name'],
            'submission_id' => (string) $file['submission_id'],
        ],
        $file['original_name']
    );
    $fileName = ensure_original_extension($fileName, $file['original_name']);
    $fileName = sanitize_name_component($fileName);

    $zip->addFile($realPath, $fileName);
}

$zip->close();

$baseName = sanitize_name_component($files[0]['exam_title']);
$folderName = apply_name_template(
    $files[0]['folder_name_template'] ?? '',
    [
        'exam_title' => $files[0]['exam_title'],
        'student_name' => $files[0]['student_name'],
        'candidate_number' => $files[0]['candidate_number'],
        'submission_id' => (string) $files[0]['submission_id'],
    ],
    sprintf('%s_%s', $files[0]['candidate_number'], $files[0]['student_name'])
);
$folderName = sanitize_name_component($folderName);
$downloadName = sprintf('%s_%s.zip', $baseName, $folderName);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);
unlink($zipPath);
exit;
