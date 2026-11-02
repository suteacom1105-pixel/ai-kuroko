<?php
// /api/* の入口（.htaccess からここに来る）
declare(strict_types=1);
require __DIR__ . '/_app.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
try {
    dispatch_request($_SERVER['REQUEST_METHOD'], $path);
    $res = new HttpResponse(500, 'no response');
} catch (HttpResponse $r) {
    $res = $r;
} catch (Throwable $e) {
    error_log('[kuroko] ' . $path . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $res = new HttpResponse(500, 'internal error');
}
http_response_code($res->status);
foreach ($res->headers ?: ['Content-Type: text/plain; charset=utf-8'] as $h) {
    header($h);
}
echo $res->body;
