<?php

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function build_exam_file_download_name(string $title, string $original): string
{
    $title = trim($title);
    if ($title === '') {
        return basename($original);
    }
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $safeTitle = sanitize_name_component($title);
    if ($safeTitle === '') {
        return basename($original);
    }
    return $ext !== '' ? $safeTitle . '.' . $ext : $safeTitle;
}

$fileId = (int) ($_GET['id'] ?? 0);
if ($fileId <= 0) {
    http_response_code(400);
    echo 'Invalid file.';
    exit;
}

$stmt = db()->prepare(
<<<<<<< HEAD
    'SELECT ef.*, e.start_time, e.end_time, e.buffer_pre_minutes, e.buffer_post_minutes, e.is_completed
=======
    'SELECT ef.*, e.start_time, e.end_time, e.buffer_pre_minutes, e.buffer_post_minutes, e.is_completed,
            e.access_password_hash, e.student_roster_enabled, e.student_roster_mode
>>>>>>> 6046a52 (Add Individual Exam File Logging)
     FROM exam_files ef
     JOIN exams e ON e.id = ef.exam_id
     WHERE ef.id = ?'
);
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

$now = new DateTimeImmutable('now');
if (!exam_is_active($file, $now)) {
    http_response_code(403);
    echo 'Exam not accepting submissions.';
    exit;
}

$needsExamPassword = !empty($file['access_password_hash']);
$needsRosterPassword = !empty($file['student_roster_enabled']) && ($file['student_roster_mode'] ?? '') === 'password';
if ($needsExamPassword && empty($_SESSION['exam_access_' . (int) $file['exam_id']])) {
    http_response_code(403);
    echo 'Exam access password required.';
    exit;
}
if ($needsRosterPassword && empty($_SESSION['exam_roster_student_' . (int) $file['exam_id']])) {
    http_response_code(403);
    echo 'Student password required.';
    exit;
}

if ($needsRosterPassword) {
    $studentId = (int) ($_SESSION['exam_roster_student_' . (int) $file['exam_id']] ?? 0);
    if ($studentId > 0) {
        $stmt = db()->prepare(
            'INSERT INTO exam_material_downloads (exam_id, exam_student_id, downloaded_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE downloaded_at = VALUES(downloaded_at)'
        );
        $stmt->execute([(int) $file['exam_id'], $studentId, now_utc_string()]);
    }
}

$config = require __DIR__ . '/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');
$storedPath = $uploadsDir . '/' . ltrim((string) $file['stored_path'], '/');
$realPath = realpath($storedPath);
$uploadsRoot = realpath($uploadsDir);

if (!$realPath || !$uploadsRoot || strpos($realPath, $uploadsRoot) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    echo 'File not available.';
    exit;
}

$downloadName = build_exam_file_download_name((string) ($file['title'] ?? ''), (string) $file['original_name']);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
header('Content-Length: ' . (string) filesize($realPath));
readfile($realPath);
exit;
