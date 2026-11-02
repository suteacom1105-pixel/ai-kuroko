<?php
// 本番切り替え: php kuroko_app/cli/go_live.php
// 定期実行が LINE に送るようになる。案件メールのチェックは「今」以降に届いた分から（旧 Vercel 版との二重通知を防ぐ）
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';

touch(APP_DIR . '/live');
kv_set(GMAIL_LAST_CHECKED_KEY, jst_now()->getTimestamp());
echo "本番に切り替えました\n";
