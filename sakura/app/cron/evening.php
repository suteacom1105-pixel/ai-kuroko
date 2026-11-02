<?php
// 前日23時のお知らせ（翌日に予定がある時だけ送る）: php kuroko_app/cron/evening.php [--dry-run]
// 本番切り替え前（live ファイルが無い時）と --dry-run の時は、LINE に送らず画面に表示するだけ
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';

$message = build_evening_message();
if ($message === null) {
    echo date('c') . " 明日の予定なし（送信しません）\n";
    exit;
}
if (!is_live() || in_array('--dry-run', $argv, true)) {
    echo "[送信しません]\n{$message}\n";
    exit;
}
line_push((string)config('line.owner_user_id'), $message);
echo date('c') . " sent\n";
