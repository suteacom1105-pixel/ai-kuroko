<?php
declare(strict_types=1);

// ===== LINE Messaging API =====

function line_verify_signature(string $rawBody, ?string $signature): bool
{
    $secret = (string)config('line.channel_secret');
    if ($secret === '' || !is_string($signature) || $signature === '') {
        return false;
    }
    return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature);
}

function line_auth_header(): string
{
    $token = (string)config('line.channel_access_token');
    if ($token === '') {
        throw new RuntimeException('LINE のチャネルアクセストークンが未設定です');
    }
    return 'Authorization: Bearer ' . $token;
}

// LINE のテキストメッセージは5000文字が上限
function line_truncate(string $text): string
{
    $limit = 4900;
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…(省略)' : $text;
}

function line_reply(string $replyToken, string $text): void
{
    http_json('POST', 'https://api.line.me/v2/bot/message/reply', [line_auth_header()], [
        'replyToken' => $replyToken,
        'messages' => [['type' => 'text', 'text' => line_truncate($text)]],
    ]);
}

function line_push(string $to, string $text): void
{
    http_json('POST', 'https://api.line.me/v2/bot/message/push', [line_auth_header()], [
        'to' => $to,
        'messages' => [['type' => 'text', 'text' => line_truncate($text)]],
    ]);
}

function line_display_name(string $userId): string
{
    try {
        $profile = http_json('GET', 'https://api.line.me/v2/bot/profile/' . rawurlencode($userId), [line_auth_header()]);
        return (string)($profile['displayName'] ?? 'スタッフ');
    } catch (Throwable) {
        return 'スタッフ';
    }
}

const IMAGE_MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

/** 送られてきた画像の中身。Claude が受け付ける形式以外は jpeg として扱う */
function line_image_content(string $messageId): array
{
    $res = http_request('GET', 'https://api-data.line.me/v2/bot/message/' . rawurlencode($messageId) . '/content', [line_auth_header()]);
    if ($res['status'] !== 200) {
        throw new HttpError($res['status'], '', "LINE の画像取得に失敗しました (HTTP {$res['status']})");
    }
    $type = strtolower(trim(explode(';', $res['headers']['content-type'] ?? '')[0]));
    return [
        'base64' => base64_encode($res['body']),
        'mediaType' => in_array($type, IMAGE_MEDIA_TYPES, true) ? $type : 'image/jpeg',
    ];
}

/** 'owner'（GO 本人）/ 'staff'（伝言取次のみ）/ 'unknown' */
function resolve_role(?string $userId): string
{
    if ($userId === null || $userId === '') {
        return 'unknown';
    }
    if ($userId === config('line.owner_user_id')) {
        return 'owner';
    }
    return in_array($userId, config('line.staff_user_ids') ?? [], true) ? 'staff' : 'unknown';
}
