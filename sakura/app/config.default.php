<?php
// 秘密ではない設定。鍵（LINE・Claude・Google のシークレット）はサーバーの config.secrets.php にだけ置く
return [
    'base_url' => 'https://kuroko.hizasupo.com',
    'db_path' => APP_DIR . '/data/kuroko.sqlite',
    'claude' => [
        'model' => 'claude-sonnet-5',
        'api_key' => '',
    ],
    'line' => [
        'channel_secret' => '',
        'channel_access_token' => '',
        // GO 本人（フル権限）とスタッフ（伝言取次のみ）の LINE userId
        'owner_user_id' => 'U345fd0ef9828fcfe3324b3c15242f373',
        'staff_user_ids' => [],
    ],
    'google' => [
        'client_id' => '698866649693-sem7mmmes1hehqi3fgc3u0n64aggtida.apps.googleusercontent.com',
        'client_secret' => '',
        'calendar_id' => 'primary',
        // ブランドアカウントのチャンネルは mine=true で解決できないため明示する
        'youtube_channel_id' => 'UCGHizl3f3lgu6iflVMyfJTQ',
        // アイデアメモの Google ドキュメント。空なら初回に自動作成して保存する
        'idea_doc_id' => '',
    ],
    // 気象庁の予報区コード（仙台）
    'weather' => ['office' => '040000', 'area' => '040010', 'amedas' => '34392'],
    // ひざスポ接骨院の予約システム。トークンは予約システム側の config.ai.php（同じサーバー）から読む
    'yoyaku' => [
        'base_url' => 'https://yoyaku.hizasupo.com',
        'token_file' => '/home/semic/yoyaku_app/config.ai.php',
    ],
];
