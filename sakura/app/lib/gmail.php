<?php
declare(strict_types=1);

// ===== YouTube 案件メールの通知（監視用の別 Google アカウントの Gmail を読む） =====

const GMAIL_LAST_CHECKED_KEY = 'gmail:last_checked_epoch';
const GMAIL_INITIAL_LOOKBACK_SECONDS = 25 * 60 * 60; // 前回のチェック記録が無い時は直近25時間分を見る

/** @return list<array{id: string, from: string, subject: string, snippet: string}> */
function gmail_messages_since(int $afterEpochSeconds): array
{
    $list = google_api('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages', [
        'q' => "after:{$afterEpochSeconds}",
        'maxResults' => 50,
    ], null, GOOGLE_GMAIL);

    $messages = [];
    foreach ($list['messages'] ?? [] as $ref) {
        $m = google_api('GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($ref['id']), [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject'],
        ], null, GOOGLE_GMAIL);
        $headers = [];
        foreach ($m['payload']['headers'] ?? [] as $h) {
            $headers[strtolower($h['name'])] = $h['value'];
        }
        $messages[] = [
            'id' => $ref['id'],
            'from' => $headers['from'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'snippet' => $m['snippet'] ?? '',
        ];
    }
    return $messages;
}

/** Claude に YouTube 案件（広告・PR・コラボ・タイアップ等）に当たるメールの ID だけ選ばせる */
function classify_youtube_business_emails(array $messages): array
{
    if (!$messages) {
        return [];
    }
    $list = implode("\n\n", array_map(fn($m) => "id={$m['id']}\n差出人: {$m['from']}\n件名: {$m['subject']}\n本文冒頭: {$m['snippet']}", $messages));
    $response = claude_create([
        'model' => claude_model(),
        'max_tokens' => 1024,
        'tools' => [[
            'name' => 'report_matches',
            'description' => 'YouTubeチャンネルへの案件(広告出演・PR・コラボ・タイアップ・商品提供等)の問い合わせに該当するメールのIDを報告する。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'matchingIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '該当するメッセージIDの一覧。該当なしの場合は空配列。'],
                ],
                'required' => ['matchingIds'],
            ],
        ]],
        'tool_choice' => ['type' => 'tool', 'name' => 'report_matches'],
        'messages' => [[
            'role' => 'user',
            'content' => 'これはGmailに届いた新着メールの一覧です。YouTubeチャンネル運営に関する案件' .
                '(企業・PR会社等からの広告出演・コラボ・タイアップ・商品提供・スポンサー依頼など)に該当するメールだけを' .
                "report_matchesツールで報告してください。通知メールやニュースレター、無関係な問い合わせは含めないでください。\n\n" . $list,
        ]],
    ]);
    foreach ($response['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'tool_use') {
            return $block['input']['matchingIds'] ?? [];
        }
    }
    return [];
}

/**
 * 前回チェック以降のメールを判定し、案件メールがあれば GO に LINE で知らせる（無ければ何も送らない）。
 * dryRun のときは送信も「前回チェック時刻」の更新もせず、送る予定の文面を返す
 */
function run_gmail_check(bool $dryRun = false): array
{
    $now = jst_now()->getTimestamp();
    $lastChecked = kv_get(GMAIL_LAST_CHECKED_KEY) ?? $now - GMAIL_INITIAL_LOOKBACK_SECONDS;
    $messages = gmail_messages_since((int)$lastChecked);
    $matchingIds = classify_youtube_business_emails($messages);
    $matched = array_values(array_filter($messages, fn($m) => in_array($m['id'], $matchingIds, true)));

    $text = null;
    if ($matched) {
        $text = "YouTube案件と思われるメールが届いています。\n\n" . implode("\n\n", array_map(fn($m) => "差出人: {$m['from']}\n件名: {$m['subject']}", $matched));
    }
    if (!$dryRun) {
        if ($text !== null) {
            line_push((string)config('line.owner_user_id'), $text);
        }
        kv_set(GMAIL_LAST_CHECKED_KEY, $now);
    }
    return ['checked' => count($messages), 'matched' => count($matched), 'text' => $text];
}
