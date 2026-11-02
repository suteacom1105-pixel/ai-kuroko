<?php
declare(strict_types=1);

// ===== Claude API（Messages API を直接呼ぶ） =====

function claude_create(array $payload): array
{
    $apiKey = (string)config('claude.api_key');
    if ($apiKey === '') {
        throw new RuntimeException('Claude の API キーが未設定です');
    }
    return http_json('POST', 'https://api.anthropic.com/v1/messages', [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ], $payload);
}

function claude_model(): string
{
    return (string)config('claude.model');
}

/**
 * Claude に渡すツール定義（app/tools.json）。
 * 中身の空オブジェクト（"properties": {}）を配列 [] に変えないよう、オブジェクトのまま読み込む
 */
function claude_tools(): array
{
    static $tools = null;
    return $tools ??= json_decode(file_get_contents(APP_DIR . '/tools.json'), false, 512, JSON_THROW_ON_ERROR);
}

/** 応答の content を、次のリクエストにそのまま送り返せる形にする（tool_use の input {} が [] にならないように） */
function claude_content_for_resend(array $content): array
{
    return array_map(function (array $block): array {
        if (($block['type'] ?? '') === 'tool_use') {
            $block['input'] = (object)($block['input'] ?? []);
        }
        return $block;
    }, $content);
}

function claude_text_of(array $response): string
{
    $texts = [];
    foreach ($response['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $texts[] = $block['text'];
        }
    }
    return implode("\n", $texts);
}
