<?php
// 小さなテスト用の仕組み（php tests/run.php で動かす）。外部 API は http_fake で偽物に差し替える
declare(strict_types=1);

define('APP_DIR', dirname(__DIR__) . '/app');
$GLOBALS['__test_config'] = array_replace_recursive(require APP_DIR . '/config.default.php', [
    'base_url' => 'https://kuroko.test',
    'db_path' => ':memory:',
    'claude' => ['api_key' => 'test-claude-key', 'model' => 'test-model'],
    'line' => ['channel_secret' => 'line-secret', 'channel_access_token' => 'line-token', 'owner_user_id' => 'U-owner', 'staff_user_ids' => ['U-staff']],
    'google' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'idea_doc_id' => '', 'youtube_channel_id' => 'UC-test'],
    'yoyaku' => ['base_url' => 'https://yoyaku.test', 'token' => 'yoyaku-token'],
]);
$GLOBALS['config'] = $GLOBALS['__test_config'];
require APP_DIR . '/bootstrap.php';

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__group'] ?? '', $name, $fn];
}
function group(string $name): void
{
    $GLOBALS['__group'] = $name;
}
function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? $msg . ': ' : '') . '期待 ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ' / 実際 ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function ok($cond, string $msg = ''): void
{
    eq(true, (bool)$cond, $msg);
}
function throws_message(string $needle, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        ok(str_contains($e->getMessage(), $needle), "例外メッセージに「{$needle}」が含まれる（実際: {$e->getMessage()}）");
        return;
    }
    throw new RuntimeException("例外（{$needle}）が起きませんでした");
}

// ===== 偽の外部 API =====

function json_ok($data, int $status = 200): array
{
    return ['status' => $status, 'body' => json_encode($data, JSON_UNESCAPED_UNICODE), 'headers' => ['content-type' => 'application/json']];
}

/**
 * $routes: [[メソッド, URL の先頭, 応答（配列 or fn(url, body, headers) => 配列）], ...]
 * 呼ばれた通信は $GLOBALS['__calls'] に記録する。どれにも当たらない通信は失敗にする
 */
function fake_http(array $routes): void
{
    $GLOBALS['__calls'] = [];
    $GLOBALS['http_fake'] = function (string $method, string $url, array $headers, ?string $body) use ($routes): array {
        $GLOBALS['__calls'][] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        foreach ($routes as [$m, $prefix, $res]) {
            if ($m === $method && str_starts_with($url, $prefix)) {
                return is_callable($res) ? $res($url, $body, $headers) : $res;
            }
        }
        throw new RuntimeException("想定外の通信: {$method} {$url}");
    };
}

/** 記録した通信のうち URL が $prefix で始まるもの */
function calls_to(string $prefix, ?string $method = null): array
{
    return array_values(array_filter($GLOBALS['__calls'], fn($c) => str_starts_with($c['url'], $prefix) && ($method === null || $c['method'] === $method)));
}

function body_of(array $call): array
{
    return json_decode((string)$call['body'], true);
}

/** Google の refresh_token が保存済みで、アクセストークンが発行される状態にする */
function google_routes(): array
{
    return [['POST', 'https://oauth2.googleapis.com/token', json_ok(['access_token' => 'g-access', 'expires_in' => 3600])]];
}
function with_google_tokens(): void
{
    kv_set('google:refresh_token', 'r-primary');
    kv_set('google:gmail_refresh_token', 'r-gmail');
}

function claude_text_response(string $text): array
{
    return json_ok(['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']);
}

/** LINE からの Webhook を正しい署名付きで送る → [status, body] */
function post_webhook(array $events, ?string $signature = null): array
{
    $raw = json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);
    $GLOBALS['__raw_body'] = $raw;
    $_SERVER['HTTP_X_LINE_SIGNATURE'] = $signature ?? base64_encode(hash_hmac('sha256', $raw, 'line-secret', true));
    return call_route('POST', '/api/webhook');
}

function call_route(string $method, string $path, array $query = []): array
{
    $_GET = $query;
    try {
        dispatch_request($method, $path);
    } catch (HttpResponse $r) {
        return [$r->status, $r->body, $r->headers];
    }
}

function run_tests(): void
{
    $fail = 0;
    foreach ($GLOBALS['__tests'] as [$group, $name, $fn]) {
        // テストごとに設定・DB・偽の通信・現在時刻を初期化する
        $GLOBALS['config'] = $GLOBALS['__test_config'];
        unset($GLOBALS['__db'], $GLOBALS['http_fake'], $GLOBALS['__raw_body']);
        $GLOBALS['__now'] = '2026-11-02 09:30:00';
        $GLOBALS['__calls'] = [];
        try {
            $fn();
            echo "  ✓ {$group} › {$name}\n";
        } catch (Throwable $e) {
            $fail++;
            echo "  ✗ {$group} › {$name}\n      " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
    $n = count($GLOBALS['__tests']);
    echo "\n" . ($n - $fail) . " / {$n} 件成功" . ($fail ? "（{$fail} 件失敗）" : '') . "\n";
    exit($fail ? 1 : 0);
}
