<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_GET['exam_id'] ?? 0);

if ($examId <= 0) {
    http_response_code(400);
    echo 'Invalid exam.';
    exit;
}

$stmt = db()->prepare(
    'SELECT e.title AS exam_title, e.file_name_template, e.folder_name_template, e.exam_code, e.id AS exam_id,
            s.id AS submission_id, s.student_name, s.student_first_name, s.student_last_name, s.candidate_number,
            sf.original_name, sf.stored_path, ed.title AS document_title
     FROM exams e
     JOIN submissions s ON s.exam_id = e.id
     JOIN submission_files sf ON sf.submission_id = s.id
     JOIN exam_documents ed ON ed.id = sf.exam_document_id
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

    $examIdentifier = $file['exam_code'] ?? $file['exam_id'] ?? '';
    $firstInitial = $file['student_first_name'] !== '' ? substr($file['student_first_name'], 0, 1) : '';
    $lastInitial = $file['student_last_name'] !== '' ? substr($file['student_last_name'], 0, 1) : '';
    $fullName = trim($file['student_first_name'] . ' ' . $file['student_last_name']);
    $fallbackName = $fullName !== '' ? $fullName : $file['student_name'];
    $folder = apply_name_template(
        $file['folder_name_template'] ?? '',
        [
            'exam_id' => $examIdentifier,
            'exam_title' => $file['exam_title'],
            'student_firstname' => $file['student_first_name'],
            'student_surname' => $file['student_last_name'],
            'student_firstname_initial' => $firstInitial,
            'student_surname_initial' => $lastInitial,
            'candidate_number' => $file['candidate_number'],
            'submission_id' => (string) $file['submission_id'],
        ],
        sprintf('%s_%s', $file['candidate_number'], $fallbackName)
    );
    $folder = sanitize_name_component($folder);

    $fileName = apply_name_template(
        $file['file_name_template'] ?? '',
        [
            'exam_id' => $examIdentifier,
            'exam_title' => $file['exam_title'],
            'student_firstname' => $file['student_first_name'],
            'student_surname' => $file['student_last_name'],
            'student_firstname_initial' => $firstInitial,
            'student_surname_initial' => $lastInitial,
            'candidate_number' => $file['candidate_number'],
            'document_title' => $file['document_title'],
            'original_name' => $file['original_name'],
            'submission_id' => (string) $file['submission_id'],
        ],
        $file['original_name']
    );
    $fileName = ensure_original_extension($fileName, $file['original_name']);
    $fileName = sanitize_name_component($fileName);

    $zip->addFile($realPath, $folder . '/' . $fileName);
}

$zip->close();

$baseName = sanitize_name_component($files[0]['exam_title']);
$downloadName = sprintf('%s_all_submissions.zip', $baseName);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);
unlink($zipPath);
exit;
