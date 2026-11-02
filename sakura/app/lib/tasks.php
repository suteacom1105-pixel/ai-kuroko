<?php
declare(strict_types=1);

// ===== Google ToDo リスト（タスク） =====

function tasks_url(string $taskId = ''): string
{
    $url = 'https://tasks.googleapis.com/tasks/v1/lists/@default/tasks';
    return $taskId === '' ? $url : $url . '/' . rawurlencode($taskId);
}

function to_task_item(array $t): array
{
    return [
        'id' => $t['id'] ?? '',
        'title' => $t['title'] ?? '(タイトルなし)',
        'notes' => $t['notes'] ?? null,
        'due' => isset($t['due']) ? substr($t['due'], 0, 10) : null,
        'completed' => ($t['status'] ?? '') === 'completed',
    ];
}

function list_tasks(bool $includeCompleted = false): array
{
    $res = google_api('GET', tasks_url(), ['showCompleted' => $includeCompleted, 'showHidden' => $includeCompleted]);
    return array_map('to_task_item', $res['items'] ?? []);
}

function create_task(string $title, ?string $dueDate = null, ?string $notes = null): array
{
    $body = ['title' => $title];
    if (non_empty($notes)) {
        $body['notes'] = $notes;
    }
    // Google タスクの期限は時刻を無視して日付だけ扱われるため 00:00:00Z で固定する
    if (non_empty($dueDate)) {
        $body['due'] = "{$dueDate}T00:00:00.000Z";
    }
    return to_task_item(google_api('POST', tasks_url(), [], $body));
}

function complete_task(string $taskId): array
{
    return to_task_item(google_api('PATCH', tasks_url($taskId), [], ['status' => 'completed']));
}

function delete_task(string $taskId): void
{
    google_api('DELETE', tasks_url($taskId));
}
