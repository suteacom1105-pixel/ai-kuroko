<?php
// 設定・連携の状態を確認する（鍵の中身は表示しない）: php kuroko_app/cli/status.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';

$set = fn($v) => $v !== null && $v !== '' ? 'OK' : '未設定';
echo 'LINE チャネルシークレット: ' . $set(config('line.channel_secret')) . "\n";
echo 'LINE アクセストークン: ' . $set(config('line.channel_access_token')) . "\n";
echo 'Claude API キー: ' . $set(config('claude.api_key')) . "\n";
echo 'Google クライアントシークレット: ' . $set(config('google.client_secret')) . "\n";
echo 'Google 連携(秘書用): ' . $set(kv_get('google:refresh_token')) . "\n";
echo 'Google 連携(Gmail監視用): ' . $set(kv_get('google:gmail_refresh_token')) . "\n";
try {
    clinic_token();
    echo "予約システムのトークン: OK\n";
} catch (Throwable) {
    echo "予約システムのトークン: 未設定\n";
}
echo 'アイデアメモのドキュメント: ' . (config('google.idea_doc_id') ?: (kv_get(IDEA_DOC_ID_KEY) ?: '(未作成。初回のメモで自動作成)')) . "\n";
echo '本番切り替え: ' . (is_live() ? '済み' : 'まだ（定期実行は送信しない）') . "\n";
