<?php
declare(strict_types=1);

/** 現在時刻（JST）。テストでは $GLOBALS['__now'] で固定できる */
function jst_now(): DateTimeImmutable
{
    $tz = new DateTimeZone('Asia/Tokyo');
    return isset($GLOBALS['__now']) ? new DateTimeImmutable($GLOBALS['__now'], $tz) : new DateTimeImmutable('now', $tz);
}

/** 今日から offsetDays 日後の日付 YYYY-MM-DD（JST） */
function jst_date_string(int $offsetDays = 0): string
{
    return jst_now()->modify("{$offsetDays} day")->format('Y-m-d');
}

/** 「2026-08-12 18:23」形式 */
function jst_now_label(): string
{
    return jst_now()->format('Y-m-d H:i');
}
