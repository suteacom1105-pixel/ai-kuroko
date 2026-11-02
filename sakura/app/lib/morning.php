<?php
declare(strict_types=1);

// ===== 毎朝の通知（仙台の天気＋今日/明日の予定＋未完了タスク） =====

function format_event_line(array $event): string
{
    $detail = non_empty($event['description'] ?? null) ? '(' . str_replace("\n", ' / ', $event['description']) . ')' : '';
    if ($event['isAllDay']) {
        return "・終日 {$event['summary']}{$detail}";
    }
    return '・' . substr($event['start'], 11, 5) . " {$event['summary']}{$detail}";
}

function format_events_section(array $events, string $date): string
{
    $day = array_filter($events, fn($e) => substr($e['start'], 0, 10) === $date);
    return $day ? implode("\n", array_map('format_event_line', $day)) : '予定はありません';
}

function format_task_line(array $task): string
{
    if ($task['due'] === null) {
        return "・{$task['title']}";
    }
    [, $month, $day] = explode('-', $task['due']);
    return "・{$task['title']}(期限: " . (int)$month . '/' . (int)$day . ')';
}

const WEEKDAYS_JA = ['日', '月', '火', '水', '木', '金', '土'];

/** 「11/3(火)」形式 */
function short_date_label(string $date): string
{
    $d = new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo'));
    return $d->format('n/j') . '(' . WEEKDAYS_JA[(int)$d->format('w')] . ')';
}

/** 前日23時のお知らせ。翌日に予定が無ければ null（送らない） */
function build_evening_message(): ?string
{
    $tomorrow = jst_date_string(1);
    $events = array_filter(
        list_events("{$tomorrow}T00:00:00+09:00", "{$tomorrow}T23:59:59+09:00"),
        fn($e) => substr($e['start'], 0, 10) === $tomorrow
    );
    if (!$events) {
        return null;
    }
    return '明日 ' . short_date_label($tomorrow) . " の予定です🌙\n" . implode("\n", array_map('format_event_line', $events));
}

function build_morning_message(): string
{
    $today = jst_date_string(0);
    $tomorrow = jst_date_string(1);
    $events = list_events("{$today}T00:00:00+09:00", "{$tomorrow}T23:59:59+09:00");
    $tasks = list_tasks(false);
    return implode("\n", [
        'おはようございます☀️',
        '【今日の天気(仙台)】',
        format_weather_line(sendai_weather_today()),
        '',
        '【今日の予定】',
        format_events_section($events, $today),
        '',
        '【明日の予定】',
        format_events_section($events, $tomorrow),
        '',
        '【未完了のタスク】',
        $tasks ? implode("\n", array_map('format_task_line', $tasks)) : '未完了のタスクはありません',
    ]);
}
