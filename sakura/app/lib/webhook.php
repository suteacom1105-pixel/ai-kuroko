<?php
declare(strict_types=1);

// ===== LINE Webhook の受け口（userId で GO 本人＝秘書／スタッフ＝伝言取次に振り分ける） =====

/** 署名が正しければ各イベントを処理する。LINE 側の再送ループを避けるため、内部エラーでも 200 を返す */
function handle_webhook(string $rawBody, ?string $signature): never
{
    if (!line_verify_signature($rawBody, $signature)) {
        respond_text('invalid signature', 401);
    }
    $body = json_decode($rawBody, true);
    foreach ($body['events'] ?? [] as $event) {
        try {
            handle_line_event($event);
        } catch (Throwable $e) {
            error_log('[kuroko webhook] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
    respond_text('', 200);
}

function handle_line_event(array $event): void
{
    $type = $event['message']['type'] ?? null;
    if (($event['type'] ?? null) !== 'message' || !in_array($type, ['text', 'image'], true)) {
        return;
    }
    $userId = $event['source']['userId'] ?? null;
    $role = resolve_role($userId);
    if ($role === 'unknown') {
        // 個人利用のため GO 本人・スタッフ以外には応答しない（スタッフ登録時に userId を調べられるよう記録だけ残す）
        error_log('[kuroko webhook] unrecognized LINE userId (未登録のuserId): ' . ($userId ?? '(なし)'));
        return;
    }

    if ($type === 'image') {
        // 画像（チケットのスクショ等）からの予定登録は GO 本人のみ
        if ($role !== 'owner') {
            return;
        }
        line_reply($event['replyToken'], handle_secretary_image($userId, line_image_content($event['message']['id'])));
        return;
    }

    $text = (string)$event['message']['text'];
    $reply = $role === 'owner' ? handle_secretary_message($userId, $text) : handle_frontdesk_message($userId, $text);
    line_reply($event['replyToken'], $reply);
}

function handle_oauth_callback(string $cred): never
{
    $code = $_GET['code'] ?? null;
    $authorize = GOOGLE_CREDENTIALS[$cred]['authorize_path'];
    if (!is_string($code) || $code === '') {
        respond_text("認証コードがありません。もう一度 {$authorize} からやり直してください。", 400);
    }
    try {
        google_exchange_code($cred, $code);
    } catch (Throwable $e) {
        error_log('[kuroko oauth] ' . $e->getMessage());
        respond_text('Google連携に失敗しました: ' . $e->getMessage(), 500);
    }
    respond_text($cred === GOOGLE_GMAIL ? 'Gmail連携が完了しました。このタブは閉じて問題ありません。' : 'Google連携が完了しました。このタブは閉じて問題ありません。');
}

/** 公開フォルダの index.php から呼ばれる */
function dispatch_request(string $method, string $path): never
{
    switch ("{$method} {$path}") {
        case 'POST /api/webhook':
            handle_webhook($GLOBALS['__raw_body'] ?? file_get_contents('php://input'), $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? null);
        case 'GET /api/oauth/google/authorize':
            respond_redirect(google_auth_url(GOOGLE_PRIMARY));
        case 'GET /api/oauth/google/callback':
            handle_oauth_callback(GOOGLE_PRIMARY);
        case 'GET /api/oauth/gmail/authorize':
            respond_redirect(google_auth_url(GOOGLE_GMAIL));
        case 'GET /api/oauth/gmail/callback':
            handle_oauth_callback(GOOGLE_GMAIL);
        default:
            respond_text('not found', 404);
    }
}
