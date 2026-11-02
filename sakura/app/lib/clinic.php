<?php
declare(strict_types=1);

// ===== ひざスポ接骨院の予約システム（https://yoyaku.hizasupo.com）の管理 API =====
// 管理画面と同じ権限で操作するため X-Kuroko-Token ヘッダーを付ける。トークンは同じサーバーにある
// 予約システムの config.ai.php（予約システム側の deploy/set_kuroko_token.sh で作成）から読む

const CLINIC_RESOURCES = ['treatment', 'treatment2', 'training'];

// 予約システムが返すエラーコードを、黒子がそのまま返信に使える日本語にする
const CLINIC_ERROR_MESSAGES = [
    'SLOT_UNAVAILABLE' => 'その時間は既に予約か予約不可が入っていて空いていません',
    'OUTSIDE_BUSINESS_HOURS' => '営業時間外・休診日、または予約不可の時間帯のため予約できません',
    'MENU_NOT_FOUND' => 'メニューが見つかりません',
    'NOT_FOUND' => '予約が見つかりません',
    'UNAUTHORIZED' => '予約システムの認証に失敗しました(予約システムの config.ai.php を確認してください)',
];

function clinic_token(): string
{
    $token = (string)config('yoyaku.token');
    if ($token === '') {
        $file = (string)config('yoyaku.token_file');
        if ($file !== '' && is_file($file)) {
            $token = (string)((require $file)['ai_secretary']['token'] ?? '');
        }
    }
    if ($token === '') {
        throw new RuntimeException('予約システムのトークンが未設定です。予約システム側で deploy/set_kuroko_token.sh を実行してください。');
    }
    return $token;
}

function clinic_api(string $method, string $path, array $query = [], $body = null): array
{
    $url = url_with_query(rtrim((string)config('yoyaku.base_url'), '/') . $path, $query);
    try {
        return http_json($method, $url, ['X-Kuroko-Token: ' . clinic_token()], $body);
    } catch (HttpError $e) {
        $code = json_decode($e->responseBody, true)['error'] ?? "HTTP {$e->status}";
        throw new RuntimeException(CLINIC_ERROR_MESSAGES[$code] ?? "予約システムのエラー: {$code}");
    }
}

function clinic_list_menus(): array
{
    return clinic_api('GET', '/api/menus')['menus'] ?? [];
}

/** 指定日の患者予約と予約不可の時間帯 */
function clinic_get_day(string $date): array
{
    $data = clinic_api('GET', '/api/admin/day', ['date' => $date]);
    // 体操教室は伊藤列とトレーニング列の両方に出るので id で重複を除く
    $byId = [];
    foreach (array_merge($data['treatments'] ?? [], $data['treatments2'] ?? [], $data['trainings'] ?? []) as $b) {
        $byId[$b['id']] = $b;
    }
    $bookings = array_values($byId);
    usort($bookings, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
    return [
        'date' => $date,
        'isHoliday' => (bool)($data['isHoliday'] ?? false),
        'bookings' => array_map(fn($b) => [
            'bookingId' => $b['id'],
            'start' => $b['start_time'],
            'end' => $b['end_time'],
            'patientName' => $b['customer_name'],
            'menu' => $b['menu_name'],
            'type' => $b['type'],
            'status' => $b['status'],
        ], $bookings),
        'blocks' => array_map(fn($b) => [
            'blockId' => $b['id'],
            'batchId' => $b['batch_id'],
            'resource' => $b['resource'],
            'start' => $b['start_time'],
            'end' => $b['end_time'],
            'note' => $b['note'],
        ], $data['blocks'] ?? []),
    ];
}

function clinic_create_booking(string $patientName, string $menuId, string $date, string $startTime, ?string $phone = null): array
{
    $data = clinic_api('POST', '/api/bookings', [], [
        'name' => $patientName, 'menuId' => $menuId, 'date' => $date, 'startTime' => $startTime, 'phone' => $phone ?? '',
    ]);
    return ['bookingId' => $data['bookingId'] ?? null];
}

function clinic_cancel_booking(string $bookingId): array
{
    clinic_api('DELETE', '/api/admin/bookings/' . rawurlencode($bookingId));
    return ['cancelled' => true];
}

function clinic_reschedule_booking(string $bookingId, string $date, string $startTime): array
{
    clinic_api('PATCH', '/api/admin/bookings/' . rawurlencode($bookingId), [], ['date' => $date, 'startTime' => $startTime]);
    return ['rescheduled' => true];
}

function clinic_block_time(string $date, string $startTime, string $endTime, array $resources, ?string $note = null): array
{
    clinic_api('POST', '/api/admin/blocks', [], [
        'date' => $date, 'start_time' => $startTime, 'end_time' => $endTime, 'resources' => array_values($resources), 'note' => $note,
    ]);
    return ['blocked' => true];
}

function clinic_unblock_time(string $batchId): array
{
    clinic_api('DELETE', '/api/admin/blocks', ['batch_id' => $batchId]);
    return ['unblocked' => true];
}
