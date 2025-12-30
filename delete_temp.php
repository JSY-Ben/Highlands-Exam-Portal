<?php

declare(strict_types=1);

require __DIR__ . '/helpers.php';

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');
$tmpDir = $uploadsDir . '/tmp';

$token = trim((string) ($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid token.']);
    exit;
}

$tmpFile = $tmpDir . '/' . $token . '.upload';
$tmpMeta = $tmpDir . '/' . $token . '.json';

if (is_file($tmpFile)) {
    @unlink($tmpFile);
}
if (is_file($tmpMeta)) {
    @unlink($tmpMeta);
}

echo json_encode(['status' => 'ok']);
