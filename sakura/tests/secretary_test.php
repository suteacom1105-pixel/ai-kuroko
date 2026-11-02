<?php
declare(strict_types=1);

group('会話ループ');
test('ツール呼び出し → 結果を渡して最終回答（空の input {} は {} のまま送り返す）', function () {
    $step = 0;
    fake_http(array_merge(google_routes(), [
        ['POST', 'https://api.anthropic.com/v1/messages', function () use (&$step) {
            $step++;
            if ($step === 1) {
                return json_ok(['stop_reason' => 'tool_use', 'content' => [
                    ['type' => 'text', 'text' => '確認します'],
                    ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'list_events', 'input' => ['startDate' => '2026-11-02', 'endDate' => '2026-11-02']],
                    ['type' => 'tool_use', 'id' => 'tu2', 'name' => 'clinic_list_menus', 'input' => new stdClass()],
                ]]);
            }
            return claude_text_response('今日は歯医者の予定があります');
        }],
        ['GET', 'https://www.googleapis.com/calendar/v3/calendars/primary/events', json_ok(['items' => [
            ['id' => 'e1', 'summary' => '歯医者', 'start' => ['dateTime' => '2026-11-02T15:00:00+09:00'], 'end' => ['dateTime' => '2026-11-02T16:00:00+09:00']],
        ]])],
        ['GET', 'https://yoyaku.test/api/menus', json_ok(['menus' => [['id' => 'm1', 'name' => 'ひざ治療', 'type' => 'treatment']]])],
    ]));
    with_google_tokens();
    eq('今日は歯医者の予定があります', handle_secretary_message('U-owner', '今日の予定は？'));

    $cal = calls_to('https://www.googleapis.com/calendar/v3/calendars/primary/events')[0]['url'];
    parse_str(parse_url($cal, PHP_URL_QUERY), $q);
    eq(['2026-11-02T00:00:00+09:00', '2026-11-02T23:59:59+09:00', 'Asia/Tokyo', 'true'], [$q['timeMin'], $q['timeMax'], $q['timeZone'], $q['singleEvents']]);

    $second = calls_to('https://api.anthropic.com/v1/messages')[1]['body'];
    ok(str_contains($second, '"name":"clinic_list_menus","input":{}'), '空の input はオブジェクトのまま');
    $msgs = json_decode($second, true)['messages'];
    eq(['user', 'assistant', 'user'], array_column($msgs, 'role'));
    eq(['tu1', 'tu2'], array_column($msgs[2]['content'], 'tool_use_id'));
    ok(str_contains($msgs[2]['content'][0]['content'], '歯医者'), 'ツール結果を渡す');
    eq([['role' => 'user', 'content' => '今日の予定は？'], ['role' => 'assistant', 'content' => '今日は歯医者の予定があります']], get_conversation('U-owner'));
});
test('ツールの失敗は is_error で Claude に伝え、会話は続ける', function () {
    $step = 0;
    fake_http([
        ['POST', 'https://api.anthropic.com/v1/messages', function () use (&$step) {
            return ++$step === 1
                ? json_ok(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'list_tasks', 'input' => new stdClass()]]])
                : claude_text_response('Google連携が切れているようです');
        }],
    ]);
    eq('Google連携が切れているようです', handle_secretary_message('U-owner', 'タスクは？'));
    $result = json_decode(calls_to('https://api.anthropic.com/v1/messages')[1]['body'], true)['messages'][2]['content'][0];
    eq(true, $result['is_error']);
    ok(str_contains($result['content'], '/api/oauth/google/authorize'), '再認証の案内が Claude に渡る');
});
test('システムプロンプトに現在日時・ツール定義19個を渡す', function () {
    fake_http([['POST', 'https://api.anthropic.com/v1/messages', claude_text_response('はい')]]);
    handle_secretary_message('U-owner', 'テスト');
    $req = body_of(calls_to('https://api.anthropic.com/v1/messages')[0]);
    ok(str_contains($req['system'], '現在の日時(JST)は 2026-11-02 09:30 です。'), '日時');
    eq('test-model', $req['model']);
    eq(19, count($req['tools']));
    ok(str_contains(calls_to('https://api.anthropic.com/v1/messages')[0]['body'], '"properties":{}'), 'tools.json の空オブジェクトを保つ');
});
test('会話履歴は直近12発言まで・6時間で消える', function () {
    for ($i = 0; $i < 8; $i++) {
        append_conversation('U-owner', [['role' => 'user', 'content' => "q{$i}"], ['role' => 'assistant', 'content' => "a{$i}"]]);
    }
    $conv = get_conversation('U-owner');
    eq(12, count($conv));
    eq('q2', $conv[0]['content']);
    db()->exec('UPDATE kv SET expires_at = ' . (time() - 1));
    eq([], get_conversation('U-owner'));
});
test('Claude が何も言わなければ定型の返事', function () {
    fake_http([['POST', 'https://api.anthropic.com/v1/messages', json_ok(['stop_reason' => 'end_turn', 'content' => []])]]);
    eq(FALLBACK_REPLY, handle_secretary_message('U-owner', 'テスト'));
});
