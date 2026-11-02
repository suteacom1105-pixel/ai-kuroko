<?php
declare(strict_types=1);

if (!defined('APP_DIR')) {
    define('APP_DIR', __DIR__);
}

// 設定: config.default.php（リポジトリで管理）に、サーバーだけにある秘密の設定を重ねる
// - config.secrets.php: deploy/setup_secrets.sh で作成（LINE・Claude・Google の鍵）
// - config.local.php: このパソコンで試す時だけ使う（deploy では送らない）
// （テストは先に $GLOBALS['config'] を用意してから読み込む）
if (!isset($GLOBALS['config'])) {
    $GLOBALS['config'] = require APP_DIR . '/config.default.php';
    foreach (['config.secrets.php', 'config.local.php'] as $extra) {
        if (is_file(APP_DIR . '/' . $extra)) {
            $GLOBALS['config'] = array_replace_recursive($GLOBALS['config'], require APP_DIR . '/' . $extra);
        }
    }
}

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

function config(string $key)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) {
            return null;
        }
        $v = $v[$part];
    }
    return $v;
}

const KUROKO_LIBS = [
    'http', 'db', 'dates', 'line', 'claude', 'google', 'calendar', 'tasks', 'youtube', 'docs',
    'clinic', 'weather', 'gmail', 'tools', 'secretary', 'frontdesk', 'morning', 'webhook',
];
foreach (KUROKO_LIBS as $lib) {
    require APP_DIR . "/lib/{$lib}.php";
}

/** 本番切り替え済みか（cli/go_live.php で作る）。切り替え前は定期実行が LINE に送らない */
function is_live(): bool
{
    return is_file(APP_DIR . '/live');
}
