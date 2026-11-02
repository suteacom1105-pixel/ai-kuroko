<?php
declare(strict_types=1);

// 会話履歴・Google の refresh_token・アイデアメモのドキュメントID 等を置く小さなキー・バリュー保存（SQLite）。
// 旧 Vercel 版の Upstash KV の代わり

function db(): PDO
{
    if (!isset($GLOBALS['__db'])) {
        $path = (string)config('db_path');
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS kv (key TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER)');
        $GLOBALS['__db'] = $pdo;
    }
    return $GLOBALS['__db'];
}

/** 期限切れなら null */
function kv_get(string $key)
{
    $st = db()->prepare('SELECT value FROM kv WHERE key = ? AND (expires_at IS NULL OR expires_at > ?)');
    $st->execute([$key, time()]);
    $value = $st->fetchColumn();
    return $value === false ? null : json_decode($value, true);
}

function kv_set(string $key, $value, ?int $ttlSeconds = null): void
{
    $st = db()->prepare('INSERT OR REPLACE INTO kv (key, value, expires_at) VALUES (?, ?, ?)');
    $st->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $ttlSeconds === null ? null : time() + $ttlSeconds]);
}

function kv_del(string $key): void
{
    db()->prepare('DELETE FROM kv WHERE key = ?')->execute([$key]);
}

// ===== 会話履歴（6時間で破棄、直近12発言まで） =====

const CONVERSATION_TTL_SECONDS = 60 * 60 * 6;
const CONVERSATION_MAX_TURNS = 12;

/** @return list<array{role: string, content: string}> */
function get_conversation(string $userId): array
{
    return kv_get("conv:{$userId}") ?? [];
}

function append_conversation(string $userId, array $turns): void
{
    $merged = array_slice(array_merge(get_conversation($userId), $turns), -CONVERSATION_MAX_TURNS);
    kv_set("conv:{$userId}", $merged, CONVERSATION_TTL_SECONDS);
}
