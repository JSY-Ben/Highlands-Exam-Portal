<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function now_utc_string(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function exam_is_active(array $exam, DateTimeImmutable $now): bool
{
    if (!empty($exam['is_completed'])) {
        return false;
    }

    $start = new DateTimeImmutable($exam['start_time']);
    $end = new DateTimeImmutable($exam['end_time']);

    $start = $start->modify(sprintf('-%d minutes', (int) $exam['buffer_pre_minutes']));
    $end = $end->modify(sprintf('+%d minutes', (int) $exam['buffer_post_minutes']));

    return $now >= $start && $now <= $end;
}

function format_datetime_display(string $value): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt) {
        return $value;
    }

    return $dt->format('d/m/Y g:i A');
}

function apply_name_template(?string $template, array $data, string $fallback): string
{
    $template = trim((string) $template);
    if ($template === '') {
        $template = $fallback;
    }

    $value = preg_replace_callback('/\\{([a-z_]+)\\}/i', function (array $matches) use ($data) {
        $key = strtolower($matches[1]);
        return isset($data[$key]) ? (string) $data[$key] : '';
    }, $template);

    $value = trim($value);
    return $value !== '' ? $value : $fallback;
}

function sanitize_name_component(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
    $value = trim($value, '._-');
    return $value !== '' ? $value : 'file';
}

function ensure_original_extension(string $value, string $originalName): string
{
    $originalExt = pathinfo($originalName, PATHINFO_EXTENSION);
    if ($originalExt === '') {
        return $value;
    }

    $currentExt = pathinfo($value, PATHINFO_EXTENSION);
    if ($currentExt === '') {
        return $value . '.' . $originalExt;
    }

    return $value;
}

function parse_allowed_file_types(?string $value): array
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[\\s,]+/', $value);
    $types = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $part = ltrim($part, '.');
        if ($part === '') {
            continue;
        }
        $types[] = $part;
    }

    return array_values(array_unique($types));
}

function build_accept_attribute(?string $value): string
{
    $types = parse_allowed_file_types($value);
    if (count($types) === 0) {
        return '';
    }
    $parts = array_map(static function (string $type): string {
        return '.' . $type;
    }, $types);

    return implode(',', $parts);
}

function upload_max_file_size(): int
{
    $upload = ini_get('upload_max_filesize');
    $post = ini_get('post_max_size');

    $uploadBytes = parse_ini_size_to_bytes($upload);
    $postBytes = parse_ini_size_to_bytes($post);

    if ($uploadBytes === 0 && $postBytes === 0) {
        return 0;
    }

    if ($uploadBytes === 0) {
        return $postBytes;
    }

    if ($postBytes === 0) {
        return $uploadBytes;
    }

    return min($uploadBytes, $postBytes);
}

function parse_ini_size_to_bytes(?string $value): int
{
    if ($value === null) {
        return 0;
    }

    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (int) $value;
    switch ($unit) {
        case 'g':
            return $number * 1024 * 1024 * 1024;
        case 'm':
            return $number * 1024 * 1024;
        case 'k':
            return $number * 1024;
        default:
            return (int) $value;
    }
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return 'Unlimited';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float) $bytes;
    $index = 0;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }
    return rtrim(rtrim(sprintf('%.1f', $size), '0'), '.') . ' ' . $units[$index];
}
