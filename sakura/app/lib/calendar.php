<?php
declare(strict_types=1);

// ===== Google カレンダー（GO の個人の予定） =====

const CALENDAR_TIMEZONE = 'Asia/Tokyo';

function calendar_events_url(string $eventId = ''): string
{
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode((string)(config('google.calendar_id') ?: 'primary')) . '/events';
    return $eventId === '' ? $url : $url . '/' . rawurlencode($eventId);
}

function to_calendar_event(array $e): array
{
    return [
        'id' => $e['id'] ?? '',
        'summary' => $e['summary'] ?? '(タイトルなし)',
        'description' => $e['description'] ?? null,
        'start' => $e['start']['dateTime'] ?? $e['start']['date'] ?? '',
        'end' => $e['end']['dateTime'] ?? $e['end']['date'] ?? '',
        'isAllDay' => isset($e['start']['date']),
    ];
}

/** timeMin/timeMax は +09:00 付き ISO8601。アカウントの既定タイムゾーンに依存しないよう timeZone を明示する */
function list_events(string $timeMinIso, string $timeMaxIso): array
{
    $res = google_api('GET', calendar_events_url(), [
        'timeMin' => $timeMinIso,
        'timeMax' => $timeMaxIso,
        'singleEvents' => true,
        'orderBy' => 'startTime',
        'timeZone' => CALENDAR_TIMEZONE,
    ]);
    return array_map('to_calendar_event', $res['items'] ?? []);
}

function event_time(?string $date, ?string $dateTime): ?array
{
    if (non_empty($date)) {
        return ['date' => $date];
    }
    return non_empty($dateTime) ? ['dateTime' => $dateTime, 'timeZone' => CALENDAR_TIMEZONE] : null;
}

/** 時間指定は startDateTime/endDateTime、終日は startDate/endDate（終了日は排他的） */
function create_event(array $input): array
{
    $body = [
        'summary' => $input['summary'],
        'description' => $input['description'] ?? null,
        'start' => event_time($input['startDate'] ?? null, $input['startDateTime'] ?? null),
        'end' => event_time($input['endDate'] ?? null, $input['endDateTime'] ?? null),
    ];
    return to_calendar_event(google_api('POST', calendar_events_url(), [], array_filter($body, fn($v) => $v !== null)));
}

/** 指定された項目だけ変更する */
function update_event(string $eventId, array $input): array
{
    $body = [];
    foreach (['summary', 'description'] as $k) {
        if (non_empty($input[$k] ?? null)) {
            $body[$k] = $input[$k];
        }
    }
    if ($start = event_time($input['startDate'] ?? null, $input['startDateTime'] ?? null)) {
        $body['start'] = $start;
    }
    if ($end = event_time($input['endDate'] ?? null, $input['endDateTime'] ?? null)) {
        $body['end'] = $end;
    }
    return to_calendar_event(google_api('PATCH', calendar_events_url($eventId), [], (object)$body));
}

function delete_event(string $eventId): void
{
    google_api('DELETE', calendar_events_url($eventId));
}

function non_empty($v): bool
{
    return is_string($v) && trim($v) !== '';
}
