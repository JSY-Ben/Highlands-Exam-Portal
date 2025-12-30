<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$fileId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT sf.original_name, sf.stored_path FROM submission_files sf WHERE sf.id = ?'
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

$filename = basename($file['original_name']);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($realPath));

readfile($realPath);
exit;
