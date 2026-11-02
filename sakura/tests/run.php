<?php
// テストを全部実行: php tests/run.php
declare(strict_types=1);
require __DIR__ . '/_harness.php';
foreach (glob(__DIR__ . '/*_test.php') as $f) {
    require $f;
}
run_tests();
