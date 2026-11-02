<?php
declare(strict_types=1);

group('Google');
test('アクセストークンは使い回し、無ければ refresh_token で取り直す', function () {
    fake_http(google_routes());
    with_google_tokens();
    eq('g-access', google_access_token(GOOGLE_PRIMARY));
    eq('g-access', google_access_token(GOOGLE_PRIMARY));
    eq(1, count(calls_to('https://oauth2.googleapis.com/token')), '2回目は使い回す');
    parse_str(calls_to('https://oauth2.googleapis.com/token')[0]['body'], $form);
    eq(['r-primary', 'refresh_token', 'cid', 'csecret'], [$form['refresh_token'], $form['grant_type'], $form['client_id'], $form['client_secret']]);
    google_access_token(GOOGLE_GMAIL);
    parse_str(calls_to('https://oauth2.googleapis.com/token')[1]['body'], $form);
    eq('r-gmail', $form['refresh_token'], 'Gmail 用は別の refresh_token');
});
test('refresh_token が無ければ再認証の URL を案内する', function () {
    throws_message('/api/oauth/gmail/authorize', fn() => google_access_token(GOOGLE_GMAIL));
});
test('クエリは配列なら同じキーを繰り返す', function () {
    eq('format=metadata&metadataHeaders=From&metadataHeaders=Subject&x=true', build_query(['format' => 'metadata', 'metadataHeaders' => ['From', 'Subject'], 'x' => true, 'skip' => null]));
});

group('カレンダー・タスク');
test('予定の作成: 時間指定はタイムゾーン付き、終日は date', function () {
    fake_http(array_merge(google_routes(), [['POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events', fn($u, $b) => json_ok(['id' => 'new'] + json_decode($b, true))]]));
    with_google_tokens();
    $e = create_event(['summary' => '新幹線', 'description' => "はやぶさ6号\n3号車10番E席", 'startDateTime' => '2026-11-05T08:17:00', 'endDateTime' => '2026-11-05T09:47:00']);
    eq(['dateTime' => '2026-11-05T08:17:00', 'timeZone' => 'Asia/Tokyo'], body_of(calls_to('https://www.googleapis.com/calendar')[0])['start']);
    eq(['new', false, "はやぶさ6号\n3号車10番E席"], [$e['id'], $e['isAllDay'], $e['description']]);
    create_event(['summary' => '休み', 'startDate' => '2026-11-06', 'endDate' => '2026-11-07']);
    eq(['date' => '2026-11-06'], body_of(calls_to('https://www.googleapis.com/calendar')[1])['start']);
});
test('予定の変更は指定した項目だけ送る', function () {
    fake_http(array_merge(google_routes(), [['PATCH', 'https://www.googleapis.com/calendar/v3/calendars/primary/events/ev%2F1', json_ok(['id' => 'ev/1', 'summary' => 'x', 'start' => ['date' => '2026-11-06']])]]));
    with_google_tokens();
    execute_tool('update_event', ['eventId' => 'ev/1', 'startDateTime' => '2026-11-05T17:00:00']);
    eq(['start' => ['dateTime' => '2026-11-05T17:00:00', 'timeZone' => 'Asia/Tokyo']], body_of(calls_to('https://www.googleapis.com/calendar')[0]));
});
test('タスク: 期限は 00:00:00Z、完了・一覧の形', function () {
    fake_http(array_merge(google_routes(), [
        ['POST', 'https://tasks.googleapis.com/tasks/v1/lists/@default/tasks', fn($u, $b) => json_ok(['id' => 't1', 'status' => 'needsAction'] + json_decode($b, true))],
        ['GET', 'https://tasks.googleapis.com/tasks/v1/lists/@default/tasks', json_ok(['items' => [['id' => 't1', 'title' => '請求書', 'due' => '2026-11-10T00:00:00.000Z', 'status' => 'needsAction']]])],
    ]));
    with_google_tokens();
    eq(['id' => 't1', 'title' => '請求書', 'notes' => null, 'due' => '2026-11-10', 'completed' => false], create_task('請求書', '2026-11-10'));
    eq('2026-11-10T00:00:00.000Z', body_of(calls_to('https://tasks.googleapis.com')[0])['due']);
    eq('請求書', list_tasks()[0]['title']);
    ok(str_contains(calls_to('https://tasks.googleapis.com', 'GET')[0]['url'], 'showCompleted=false'), '完了済みは除く');
});

group('アイデアメモ');
test('初回はドキュメントを作って保存、以後は空行を挟んで末尾に追記', function () {
    fake_http(array_merge(google_routes(), [
        ['POST', 'https://docs.googleapis.com/v1/documents/doc1:batchUpdate', json_ok([])],
        ['POST', 'https://docs.googleapis.com/v1/documents', json_ok(['documentId' => 'doc1'])],
    ]));
    with_google_tokens();
    eq(['line' => '[2026-11-02 09:30] 焼き鳥のタレ', 'docUrl' => 'https://docs.google.com/document/d/doc1/edit'], add_idea('焼き鳥のタレ'));
    add_idea('二つ目');
    eq(1, count(calls_to('https://docs.googleapis.com/v1/documents', 'POST')) - count(calls_to('https://docs.googleapis.com/v1/documents/doc1:batchUpdate')), '作るのは1回だけ');
    ok(str_contains(calls_to('https://docs.googleapis.com/v1/documents/doc1:batchUpdate')[0]['body'], '{"insertText":{"endOfSegmentLocation":{},"text":"\n[2026-11-02 09:30] 焼き鳥のタレ\n"}}'), '空行＋末尾に追記');
});
test('設定にドキュメントIDがあればそれを使う（旧 Vercel 版から引き継ぎ）・一覧は新しい順', function () {
    $GLOBALS['config']['google']['idea_doc_id'] = 'old-doc';
    fake_http(array_merge(google_routes(), [['GET', 'https://docs.googleapis.com/v1/documents/old-doc', json_ok(['body' => ['content' => [
        ['sectionBreak' => []],
        ['paragraph' => ['elements' => [['textRun' => ['content' => "[11-01] A\n"]]]]],
        ['paragraph' => ['elements' => [['textRun' => ['content' => "\n"]]]]],
        ['paragraph' => ['elements' => [['textRun' => ['content' => '[11-02] ']], ['textRun' => ['content' => "B\n"]]]]],
    ]]])]]));
    with_google_tokens();
    eq(['[11-02] B', '[11-01] A'], list_ideas());
    eq(['[11-02] B'], list_ideas(1));
});

group('予約システム');
test('トークンをヘッダーで送り、1日の予約は体操教室の重複を除いて時刻順', function () {
    fake_http([['GET', 'https://yoyaku.test/api/admin/day', json_ok([
        'isHoliday' => false,
        'treatments' => [
            ['id' => 'b2', 'type' => 'gym_class', 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'confirmed', 'customer_name' => '体操', 'menu_name' => '体操教室'],
            ['id' => 'b1', 'type' => 'treatment', 'start_time' => '15:00', 'end_time' => '15:30', 'status' => 'confirmed', 'customer_name' => '田中', 'menu_name' => 'ひざ治療'],
        ],
        'treatments2' => [],
        'trainings' => [['id' => 'b2', 'type' => 'gym_class', 'start_time' => '09:00', 'end_time' => '11:00', 'status' => 'confirmed', 'customer_name' => '体操', 'menu_name' => '体操教室', 'column' => 1]],
        'blocks' => [['id' => 'k1', 'batch_id' => 'bt1', 'resource' => 'treatment', 'start_time' => '20:00', 'end_time' => '21:00', 'note' => null]],
    ])]]);
    $day = clinic_get_day('2026-11-09');
    ok(in_array('X-Kuroko-Token: yoyaku-token', $GLOBALS['__calls'][0]['headers'], true), 'トークン');
    eq(['b2', 'b1'], array_column($day['bookings'], 'bookingId'));
    eq(['bt1', '20:00'], [$day['blocks'][0]['batchId'], $day['blocks'][0]['start']]);
});
test('予約システムのエラーは日本語にする', function () {
    fake_http([['POST', 'https://yoyaku.test/api/bookings', json_ok(['error' => 'SLOT_UNAVAILABLE'], 409)]]);
    throws_message('空いていません', fn() => clinic_create_booking('田中', 'm1', '2026-11-09', '15:00'));
    fake_http([['DELETE', 'https://yoyaku.test/api/admin/blocks', json_ok(['error' => 'WEIRD'], 500)]]);
    throws_message('予約システムのエラー: WEIRD', fn() => clinic_unblock_time('bt1'));
});
test('予約不可・予約の追加はそのままの形で送る', function () {
    fake_http([
        ['POST', 'https://yoyaku.test/api/admin/blocks', json_ok(['ok' => true], 201)],
        ['POST', 'https://yoyaku.test/api/bookings', json_ok(['bookingId' => 'nb'], 201)],
    ]);
    eq(['blocked' => true], execute_tool('clinic_block_time', ['date' => '2026-11-09', 'startTime' => '20:00', 'endTime' => '21:00', 'resources' => ['treatment', 'training']]));
    eq(['date' => '2026-11-09', 'start_time' => '20:00', 'end_time' => '21:00', 'resources' => ['treatment', 'training'], 'note' => null], body_of(calls_to('https://yoyaku.test/api/admin/blocks')[0]));
    eq(['bookingId' => 'nb'], execute_tool('clinic_create_booking', ['patientName' => '田中', 'menuId' => 'm1', 'date' => '2026-11-09', 'startTime' => '15:00']));
    eq('', body_of(calls_to('https://yoyaku.test/api/bookings')[0])['phone']);
});
test('トークンは予約システムの config.ai.php から読める', function () {
    $file = sys_get_temp_dir() . '/kuroko-test-config-ai.php';
    file_put_contents($file, "<?php return ['ai_secretary' => ['token' => 'from-file']];");
    $GLOBALS['config']['yoyaku'] = ['base_url' => 'https://yoyaku.test', 'token' => '', 'token_file' => $file];
    eq('from-file', clinic_token());
    unlink($file);
    throws_message('set_kuroko_token.sh', fn() => clinic_token());
});

group('毎朝の通知');
function jma_fixture(array $todayTemps): array
{
    return json_ok([
        ['timeSeries' => [
            ['timeDefines' => ['2026-11-02T05:00:00+09:00', '2026-11-03T00:00:00+09:00'], 'areas' => [['area' => ['code' => '040010'], 'weathers' => ['くもり　時々　晴れ', '晴れ']]]],
            ['timeDefines' => [], 'areas' => []],
            ['timeDefines' => ['2026-11-02T00:00:00+09:00', '2026-11-02T09:00:00+09:00'], 'areas' => [['area' => ['code' => '34392'], 'temps' => $todayTemps]]],
        ]],
        ['timeSeries' => [
            [],
            ['timeDefines' => ['2026-11-02T00:00:00+09:00'], 'areas' => [['area' => ['code' => '34392'], 'tempsMax' => ['20'], 'tempsMin' => ['8']]]],
        ]],
    ]);
}
test('天気・今日と明日の予定（メモ欄も）・未完了タスクをまとめる', function () {
    fake_http(array_merge(google_routes(), [
        ['GET', 'https://www.jma.go.jp/bosai/forecast/data/forecast/040000.json', jma_fixture(['9', '17'])],
        ['GET', 'https://www.googleapis.com/calendar/v3/calendars/primary/events', json_ok(['items' => [
            ['id' => '1', 'summary' => '新幹線 はやぶさ108号', 'description' => "はやぶさ108号\n4号車12番E席", 'start' => ['dateTime' => '2026-11-02T10:22:00+09:00'], 'end' => ['dateTime' => '2026-11-02T12:00:00+09:00']],
            ['id' => '2', 'summary' => '休診', 'start' => ['date' => '2026-11-03'], 'end' => ['date' => '2026-11-04']],
        ]])],
        ['GET', 'https://tasks.googleapis.com/tasks/v1/lists/@default/tasks', json_ok(['items' => [['id' => 't', 'title' => '請求書', 'due' => '2026-11-10T00:00:00.000Z']]])],
    ]));
    with_google_tokens();
    eq(implode("\n", [
        'おはようございます☀️', '【今日の天気(仙台)】', 'くもり時々晴れ 最高17℃/最低9℃', '',
        '【今日の予定】', '・10:22 新幹線 はやぶさ108号(はやぶさ108号 / 4号車12番E席)', '',
        '【明日の予定】', '・終日 休診', '',
        '【未完了のタスク】', '・請求書(期限: 11/10)',
    ]), build_morning_message());
});
test('当日の気温が空欄なら週間予報から、それも無ければ「不明」', function () {
    fake_http([['GET', 'https://www.jma.go.jp', jma_fixture(['', ''])]]);
    eq('くもり時々晴れ 最高20℃/最低8℃', format_weather_line(sendai_weather_today()));
    eq('晴れ 最高気温不明/最低気温不明', format_weather_line(['weatherText' => '晴れ', 'maxTemp' => null, 'minTemp' => null]));
});

group('YouTube 案件メール');
function gmail_routes(array $messages, array $matchingIds): array
{
    return array_merge(google_routes(), [
        ['GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages?', json_ok(['messages' => array_map(fn($m) => ['id' => $m['id']], $messages)])],
        ['GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/', function ($url) use ($messages) {
            $id = explode('?', basename($url))[0];
            $m = array_values(array_filter($messages, fn($x) => $x['id'] === $id))[0];
            return json_ok(['snippet' => $m['snippet'], 'payload' => ['headers' => [['name' => 'From', 'value' => $m['from']], ['name' => 'Subject', 'value' => $m['subject']]]]]);
        }],
        ['POST', 'https://api.anthropic.com/v1/messages', json_ok(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'report_matches', 'input' => ['matchingIds' => $matchingIds]]]])],
        ['POST', 'https://api.line.me/v2/bot/message/push', json_ok([])],
    ]);
}
test('案件と判定されたメールだけ GO に通知し、前回チェック時刻を更新する', function () {
    fake_http(gmail_routes([
        ['id' => 'a', 'from' => 'PR担当 <pr@example.com>', 'subject' => '動画で少しご紹介いただけないかと', 'snippet' => 'サンプルを…'],
        ['id' => 'b', 'from' => 'news@example.com', 'subject' => 'ニュースレター', 'snippet' => '今週の…'],
    ], ['a']));
    with_google_tokens();
    eq(['checked' => 2, 'matched' => 1], array_slice(run_gmail_check(), 0, 2));
    eq("YouTube案件と思われるメールが届いています。\n\n差出人: PR担当 <pr@example.com>\n件名: 動画で少しご紹介いただけないかと", body_of(calls_to('https://api.line.me')[0])['messages'][0]['text']);
    $after = strtotime('2026-11-02 09:30:00') - GMAIL_INITIAL_LOOKBACK_SECONDS;
    ok(str_contains(calls_to('https://gmail.googleapis.com/gmail/v1/users/me/messages?')[0]['url'], 'q=after%3A' . $after), '初回は直近25時間');
    ok(str_contains(calls_to('https://gmail.googleapis.com/gmail/v1/users/me/messages/a')[0]['url'], 'metadataHeaders=From&metadataHeaders=Subject'), 'ヘッダーだけ取得');
    eq(strtotime('2026-11-02 09:30:00'), kv_get(GMAIL_LAST_CHECKED_KEY));
    ok(in_array('Authorization: Bearer g-access', calls_to('https://gmail.googleapis.com')[0]['headers'], true), 'Gmail 用のトークン');
});
test('該当なしなら何も送らない・新着が無ければ Claude も呼ばない', function () {
    fake_http(gmail_routes([['id' => 'b', 'from' => 'news@example.com', 'subject' => 'ニュース', 'snippet' => '']], []));
    with_google_tokens();
    eq(['checked' => 1, 'matched' => 0, 'text' => null], run_gmail_check());
    eq([], calls_to('https://api.line.me'));
    fake_http(gmail_routes([], []));
    run_gmail_check();
    eq([], calls_to('https://api.anthropic.com'));
});
test('dry-run は送らず、前回チェック時刻も変えない', function () {
    fake_http(gmail_routes([['id' => 'a', 'from' => 'x', 'subject' => 'PR', 'snippet' => '']], ['a']));
    with_google_tokens();
    kv_set(GMAIL_LAST_CHECKED_KEY, 1000);
    ok(run_gmail_check(true)['text'] !== null, '文面は返す');
    eq([], calls_to('https://api.line.me'));
    eq(1000, kv_get(GMAIL_LAST_CHECKED_KEY));
});
