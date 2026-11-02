<?php
declare(strict_types=1);

/** Claude が呼んだツールを実行する（定義は app/tools.json） */
function execute_tool(string $name, array $input)
{
    $s = fn(string $k) => isset($input[$k]) ? (string)$input[$k] : null;
    switch ($name) {
        case 'list_events':
            return list_events("{$s('startDate')}T00:00:00+09:00", "{$s('endDate')}T23:59:59+09:00");
        case 'create_event':
            return create_event($input);
        case 'update_event':
            $id = (string)$input['eventId'];
            unset($input['eventId']);
            return update_event($id, $input);
        case 'delete_event':
            delete_event((string)$input['eventId']);
            return ['deleted' => true];
        case 'list_tasks':
            return list_tasks((bool)($input['includeCompleted'] ?? false));
        case 'create_task':
            return create_task((string)$input['title'], $s('dueDate'), $s('notes'));
        case 'complete_task':
            return complete_task((string)$input['taskId']);
        case 'delete_task':
            delete_task((string)$input['taskId']);
            return ['deleted' => true];
        case 'add_idea':
            return add_idea((string)$input['content']);
        case 'list_ideas':
            return list_ideas((int)($input['limit'] ?? 10));
        case 'list_recent_youtube_videos':
            return list_recent_videos((int)($input['maxResults'] ?? 5));
        case 'analyze_youtube_video':
            return get_video_analytics((string)$input['videoId'], (string)$input['publishedAt']);
        case 'clinic_get_day':
            return clinic_get_day((string)$input['date']);
        case 'clinic_list_menus':
            return clinic_list_menus();
        case 'clinic_create_booking':
            return clinic_create_booking((string)$input['patientName'], (string)$input['menuId'], (string)$input['date'], (string)$input['startTime'], $s('phone'));
        case 'clinic_cancel_booking':
            return clinic_cancel_booking((string)$input['bookingId']);
        case 'clinic_reschedule_booking':
            return clinic_reschedule_booking((string)$input['bookingId'], (string)$input['date'], (string)$input['startTime']);
        case 'clinic_block_time':
            return clinic_block_time((string)$input['date'], (string)$input['startTime'], (string)$input['endTime'], (array)($input['resources'] ?? []), $s('note'));
        case 'clinic_unblock_time':
            return clinic_unblock_time((string)$input['batchId']);
        default:
            return ['error' => "未知のツールです: {$name}"];
    }
}
