<?php
// LINE を通さずに黒子へ話しかけて返事を表示する（切り替え前の動作確認用）:
//   php kuroko_app/cli/try.php "今日の予定教えて"
// ※ツールは本物が動く（「予定に入れて」と言えば本当に登録される）。会話履歴は本番とは別（cli-test）
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';

$text = $argv[1] ?? '';
if ($text === '') {
    exit("使い方: php kuroko_app/cli/try.php \"メッセージ\"\n");
}
echo handle_secretary_message('cli-test', $text) . "\n";
