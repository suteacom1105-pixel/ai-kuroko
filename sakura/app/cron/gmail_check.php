<?php
// YouTube 案件メールのチェック: php kuroko_app/cron/gmail_check.php [--dry-run]
// 本番切り替え前（live ファイルが無い時）と --dry-run の時は、LINE に送らず結果を表示するだけ（前回チェック時刻も更新しない）
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';

$dryRun = !is_live() || in_array('--dry-run', $argv, true);
$result = run_gmail_check($dryRun);
echo date('c') . ($dryRun ? ' [送信しません]' : '') . " checked={$result['checked']} matched={$result['matched']}\n";
if ($dryRun && $result['text'] !== null) {
    echo $result['text'] . "\n";
}
