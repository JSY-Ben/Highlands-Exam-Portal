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
    $start = new DateTimeImmutable($exam['start_time']);
    $end = new DateTimeImmutable($exam['end_time']);

    $start = $start->modify(sprintf('-%d minutes', (int) $exam['buffer_pre_minutes']));
    $end = $end->modify(sprintf('+%d minutes', (int) $exam['buffer_post_minutes']));

    return $now >= $start && $now <= $end;
}
