<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_GET['exam_id'] ?? 0);
$folder = (string) ($_GET['folder'] ?? '');
$folder = basename($folder);

if ($examId <= 0 || $folder === '') {
    http_response_code(400);
    echo 'Invalid request.';
    exit;
}

$stmt = db()->prepare('SELECT title FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    echo 'Exam not found.';
    exit;
}

$archiveRoot = $uploadsDir . '/archive/exam_' . $examId;
$archivePath = $archiveRoot . '/' . $folder;
$realArchiveRoot = realpath($archiveRoot);
$realArchivePath = realpath($archivePath);

if (!$realArchiveRoot || !$realArchivePath || strpos($realArchivePath, $realArchiveRoot) !== 0) {
    http_response_code(403);
    echo 'Invalid archive path.';
    exit;
}

if (!is_dir($realArchivePath)) {
    http_response_code(404);
    echo 'Archive not found.';
    exit;
}

$files = glob($realArchivePath . '/*');
if (!is_array($files) || count($files) === 0) {
    http_response_code(404);
    echo 'No files in archive.';
    exit;
}

$zip = new ZipArchive();
$tmpName = tempnam(sys_get_temp_dir(), 'archive_');
$zipPath = $tmpName . '.zip';
rename($tmpName, $zipPath);

if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    http_response_code(500);
    echo 'Unable to create archive.';
    exit;
}

foreach ($files as $path) {
    $realPath = realpath($path);
    if (!$realPath || strpos($realPath, $realArchivePath) !== 0 || !is_file($realPath)) {
        continue;
    }
    $zip->addFile($realPath, basename($realPath));
}

$zip->close();

$baseName = sanitize_name_component($exam['title']);
$archiveName = sanitize_name_component($folder);
$downloadName = sprintf('%s_%s.zip', $baseName, $archiveName);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));

readfile($zipPath);
unlink($zipPath);
exit;
