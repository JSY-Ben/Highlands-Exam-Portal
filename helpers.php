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
