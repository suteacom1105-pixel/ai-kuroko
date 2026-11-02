<?php
declare(strict_types=1);

// ===== Google OAuth（2つの独立した資格情報） =====
// - primary: 秘書機能（Calendar/Tasks/YouTube/Docs）用。sutea.com1105@gmail.com
// - gmail:   YouTube 案件メール監視用の別アカウント（hello.adhdworld@gmail.com）
// 同じ OAuth クライアントを使うが、refresh_token は別のキーに保存して互いに上書きしない

const GOOGLE_PRIMARY = 'primary';
const GOOGLE_GMAIL = 'gmail';

const GOOGLE_CREDENTIALS = [
    GOOGLE_PRIMARY => [
        'scopes' => [
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/tasks',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
            'https://www.googleapis.com/auth/documents',
        ],
        'callback_path' => '/api/oauth/google/callback',
        'refresh_token_key' => 'google:refresh_token',
        'authorize_path' => '/api/oauth/google/authorize',
    ],
    GOOGLE_GMAIL => [
        'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        'callback_path' => '/api/oauth/gmail/callback',
        'refresh_token_key' => 'google:gmail_refresh_token',
        'authorize_path' => '/api/oauth/gmail/authorize',
    ],
];

function google_redirect_uri(string $cred): string
{
    return rtrim((string)config('base_url'), '/') . GOOGLE_CREDENTIALS[$cred]['callback_path'];
}

function google_client(): array
{
    $id = (string)config('google.client_id');
    $secret = (string)config('google.client_secret');
    if ($id === '' || $secret === '') {
        throw new RuntimeException('Google OAuth のクライアントID/シークレットが未設定です');
    }
    return [$id, $secret];
}

function google_auth_url(string $cred): string
{
    [$clientId] = google_client();
    return url_with_query('https://accounts.google.com/o/oauth2/v2/auth', [
        'client_id' => $clientId,
        'redirect_uri' => google_redirect_uri($cred),
        'response_type' => 'code',
        'scope' => implode(' ', GOOGLE_CREDENTIALS[$cred]['scopes']),
        'access_type' => 'offline',
        'prompt' => 'consent', // refresh_token を確実に受け取るため毎回同意画面を表示させる
    ]);
}

function google_exchange_code(string $cred, string $code): void
{
    [$clientId, $clientSecret] = google_client();
    $tokens = http_form('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => google_redirect_uri($cred),
        'grant_type' => 'authorization_code',
    ]);
    if (empty($tokens['refresh_token'])) {
        throw new RuntimeException('refresh_tokenが取得できませんでした。Googleアカウント設定でこのアプリの連携を一度解除してから再度認証してください。');
    }
    kv_set(GOOGLE_CREDENTIALS[$cred]['refresh_token_key'], $tokens['refresh_token']);
    kv_del("google:access_token:{$cred}");
}

/** アクセストークン（期限の2分前まで使い回し、切れたら refresh_token で取り直す） */
function google_access_token(string $cred): string
{
    $cached = kv_get("google:access_token:{$cred}");
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }
    $refreshToken = kv_get(GOOGLE_CREDENTIALS[$cred]['refresh_token_key']);
    if (!is_string($refreshToken) || $refreshToken === '') {
        $path = GOOGLE_CREDENTIALS[$cred]['authorize_path'];
        throw new RuntimeException("Googleのrefresh_tokenが未設定です。{$path} にアクセスして初回認証を行ってください。");
    }
    [$clientId, $clientSecret] = google_client();
    $tokens = http_form('https://oauth2.googleapis.com/token', [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);
    $accessToken = (string)($tokens['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Googleのアクセストークンを取得できませんでした');
    }
    kv_set("google:access_token:{$cred}", $accessToken, max(60, (int)($tokens['expires_in'] ?? 3600) - 120));
    return $accessToken;
}

function google_api(string $method, string $url, array $query = [], $body = null, string $cred = GOOGLE_PRIMARY): array
{
    return http_json($method, url_with_query($url, $query), ['Authorization: Bearer ' . google_access_token($cred)], $body);
}
