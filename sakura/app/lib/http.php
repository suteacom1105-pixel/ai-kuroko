<?php
declare(strict_types=1);

// ===== 外部 API への通信 =====

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $responseBody, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * 外部 API へのリクエスト。テストでは $GLOBALS['http_fake'] に関数を入れると、実際の通信の代わりにそれが呼ばれる
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (isset($GLOBALS['http_fake'])) {
        return ($GLOBALS['http_fake'])($method, $url, $headers, $body);
    }
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("通信エラー: {$error}");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string)$responseBody, 'headers' => $responseHeaders];
}

/** JSON を送って JSON を受け取る。2xx 以外は HttpError */
function http_json(string $method, string $url, array $headers = [], $payload = null): array
{
    $headers[] = 'Accept: application/json';
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    return http_decode($method, $url, http_request($method, $url, $headers, $body));
}

/** application/x-www-form-urlencoded を送って JSON を受け取る（Google のトークン発行用） */
function http_form(string $url, array $fields): array
{
    $res = http_request('POST', $url, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($fields));
    return http_decode('POST', $url, $res);
}

function http_decode(string $method, string $url, array $res): array
{
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $path = strtok($url, '?');
        throw new HttpError($res['status'], $res['body'], "{$method} {$path} → HTTP {$res['status']}: " . mb_substr($res['body'], 0, 300));
    }
    $data = $res['body'] === '' ? [] : json_decode($res['body'], true);
    return is_array($data) ? $data : [];
}

/** クエリ文字列。値が配列なら同じキーを繰り返す（Gmail の metadataHeaders 用） */
function build_query(array $params): string
{
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v === null) {
            continue;
        }
        foreach (is_array($v) ? $v : [$v] as $item) {
            $item = is_bool($item) ? ($item ? 'true' : 'false') : (string)$item;
            $parts[] = rawurlencode((string)$k) . '=' . rawurlencode($item);
        }
    }
    return implode('&', $parts);
}

function url_with_query(string $url, array $query): string
{
    $q = build_query($query);
    return $q === '' ? $url : $url . '?' . $q;
}

// ===== このサーバーが返す応答 =====

final class HttpResponse extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $body, public readonly array $headers = [])
    {
        parent::__construct('http response');
    }
}

function respond_text(string $text, int $status = 200): never
{
    throw new HttpResponse($status, $text, ['Content-Type: text/plain; charset=utf-8']);
}

function respond_redirect(string $url): never
{
    throw new HttpResponse(302, '', ['Location: ' . $url]);
}
