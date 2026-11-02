<?php
declare(strict_types=1);

// プログラム本体の場所：このパソコンでは ../app、さくらでは ~/kuroko_app
foreach ([__DIR__ . '/../app', __DIR__ . '/../../kuroko_app'] as $dir) {
    if (is_file($dir . '/bootstrap.php')) {
        require $dir . '/bootstrap.php';
        break;
    }
}
if (!defined('APP_DIR')) {
    http_response_code(500);
    exit('app not found');
}
