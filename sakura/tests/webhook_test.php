<?php
declare(strict_types=1);

function text_event(string $userId, string $text): array
{
    return ['type' => 'message', 'replyToken' => 'rt-1', 'source' => ['userId' => $userId], 'message' => ['type' => 'text', 'id' => 'm1', 'text' => $text]];
}

group('Webhook');
test('署名が違えば 401 で、何も処理しない', function () {
    fake_http([]);
    eq(401, post_webhook([text_event('U-owner', 'こんにちは')], 'bad-signature')[0]);
    eq([], $GLOBALS['__calls']);
});
test('GO 本人のテキスト → Claude の返事を LINE に返信する', function () {
    fake_http([
        ['POST', 'https://api.anthropic.com/v1/messages', claude_text_response('こんにちは、GOさん')],
        ['POST', 'https://api.line.me/v2/bot/message/reply', json_ok([])],
    ]);
    eq(200, post_webhook([text_event('U-owner', 'こんにちは')])[0]);
    $claude = calls_to('https://api.anthropic.com/v1/messages');
    eq(1, count($claude));
    ok(in_array('x-api-key: test-claude-key', $claude[0]['headers'], true), 'API キーを送る');
    eq([['role' => 'user', 'content' => 'こんにちは']], body_of($claude[0])['messages']);
    eq(['replyToken' => 'rt-1', 'messages' => [['type' => 'text', 'text' => 'こんにちは、GOさん']]], body_of(calls_to('https://api.line.me/v2/bot/message/reply')[0]));
});
test('スタッフのテキスト → GO に伝言を転送し、スタッフには受付の返事', function () {
    fake_http([
        ['GET', 'https://api.line.me/v2/bot/profile/U-staff', json_ok(['displayName' => '佐藤'])],
        ['POST', 'https://api.line.me/v2/bot/message/push', json_ok([])],
        ['POST', 'https://api.line.me/v2/bot/message/reply', json_ok([])],
    ]);
    post_webhook([text_event('U-staff', '10時の患者さん遅れます')]);
    eq(['to' => 'U-owner', 'messages' => [['type' => 'text', 'text' => "【伝言】佐藤さんより:\n10時の患者さん遅れます"]]], body_of(calls_to('https://api.line.me/v2/bot/message/push')[0]));
    eq('伝言を確認しました。GOに転送しました。', body_of(calls_to('https://api.line.me/v2/bot/message/reply')[0])['messages'][0]['text']);
});
test('知らない人・スタンプ・スタッフの画像には反応しない', function () {
    fake_http([]);
    $prev = ini_set('error_log', '/dev/null');
    eq(200, post_webhook([
        text_event('U-stranger', 'こんにちは'),
        ['type' => 'message', 'replyToken' => 'r', 'source' => ['userId' => 'U-owner'], 'message' => ['type' => 'sticker', 'id' => 's']],
        ['type' => 'message', 'replyToken' => 'r', 'source' => ['userId' => 'U-staff'], 'message' => ['type' => 'image', 'id' => 'i']],
        ['type' => 'follow', 'source' => ['userId' => 'U-owner']],
    ])[0]);
    ini_set('error_log', (string)$prev);
    eq([], $GLOBALS['__calls']);
});
test('GO 本人の画像 → 画像とチケット読み取りの指示を Claude に渡す（履歴に画像は残さない）', function () {
    fake_http([
        ['GET', 'https://api-data.line.me/v2/bot/message/img1/content', ['status' => 200, 'body' => 'PNGDATA', 'headers' => ['content-type' => 'image/png']]],
        ['POST', 'https://api.anthropic.com/v1/messages', claude_text_response("はやぶさ6号\n\n2026年8月5日 08:17〜09:47\n\n3号車10番E席")],
        ['POST', 'https://api.line.me/v2/bot/message/reply', json_ok([])],
    ]);
    post_webhook([['type' => 'message', 'replyToken' => 'rt-2', 'source' => ['userId' => 'U-owner'], 'message' => ['type' => 'image', 'id' => 'img1']]]);
    $content = body_of(calls_to('https://api.anthropic.com/v1/messages')[0])['messages'][0]['content'];
    eq(['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('PNGDATA')], $content[0]['source']);
    eq(TICKET_IMAGE_PROMPT, $content[1]['text']);
    eq('[チケット画像を送信]', get_conversation('U-owner')[0]['content']);
});
test('1件の処理が失敗しても 200 を返す（LINE の再送ループを避ける）', function () {
    fake_http([['POST', 'https://api.anthropic.com/v1/messages', json_ok(['error' => 'overloaded'], 529)]]);
    $prev = ini_set('error_log', '/dev/null');
    eq(200, post_webhook([text_event('U-owner', 'こんにちは')])[0]);
    ini_set('error_log', (string)$prev);
});

group('Google 認証の入口');
test('authorize は同意画面へ転送（秘書用と Gmail 用でスコープ・戻り先が別）', function () {
    [$s, , $h] = call_route('GET', '/api/oauth/google/authorize');
    eq(302, $s);
    parse_str(parse_url(substr($h[0], strlen('Location: ')), PHP_URL_QUERY), $q);
    eq('https://kuroko.test/api/oauth/google/callback', $q['redirect_uri']);
    ok(str_contains($q['scope'], '/auth/calendar') && str_contains($q['scope'], '/auth/documents'), '秘書用スコープ');
    eq(['offline', 'consent'], [$q['access_type'], $q['prompt']]);
    [, , $h] = call_route('GET', '/api/oauth/gmail/authorize');
    parse_str(parse_url(substr($h[0], strlen('Location: ')), PHP_URL_QUERY), $q);
    eq(['https://kuroko.test/api/oauth/gmail/callback', 'https://www.googleapis.com/auth/gmail.readonly'], [$q['redirect_uri'], $q['scope']]);
});
test('callback は refresh_token をそれぞれ別のキーに保存する', function () {
    fake_http([['POST', 'https://oauth2.googleapis.com/token', json_ok(['access_token' => 'a', 'refresh_token' => 'r-new-gmail'])]]);
    kv_set('google:refresh_token', 'r-primary');
    eq([200, 'Gmail連携が完了しました。このタブは閉じて問題ありません。'], array_slice(call_route('GET', '/api/oauth/gmail/callback', ['code' => 'c1']), 0, 2));
    eq(['r-primary', 'r-new-gmail'], [kv_get('google:refresh_token'), kv_get('google:gmail_refresh_token')]);
    parse_str(calls_to('https://oauth2.googleapis.com/token')[0]['body'], $form);
    eq(['c1', 'authorization_code', 'https://kuroko.test/api/oauth/gmail/callback'], [$form['code'], $form['grant_type'], $form['redirect_uri']]);
});
test('callback: コードが無い・refresh_token が返らない時はエラー表示', function () {
    eq(400, call_route('GET', '/api/oauth/google/callback')[0]);
    fake_http([['POST', 'https://oauth2.googleapis.com/token', json_ok(['access_token' => 'a'])]]);
    $prev = ini_set('error_log', '/dev/null');
    [$s, $body] = call_route('GET', '/api/oauth/google/callback', ['code' => 'c']);
    ini_set('error_log', (string)$prev);
    eq(500, $s);
    ok(str_contains($body, '連携を一度解除'), 'やり直し方を案内する');
});
test('知らない URL は 404', function () {
    eq(404, call_route('GET', '/api/nothing')[0]);
    eq(404, call_route('GET', '/api/webhook')[0]);
});
