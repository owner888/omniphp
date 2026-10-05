<?php
/**
 * 测试引导：框架的 Logger / FileCache / Config 要求应用先定义三个路径常量，测试里指向临时目录
 */

require __DIR__ . '/../vendor/autoload.php';

$runtime = sys_get_temp_dir() . '/omniphp-tests-' . getmypid();
@mkdir($runtime . '/logs', 0755, true);

define('BASE_PATH', dirname(__DIR__));
define('CONFIG_PATH', __DIR__ . '/fixtures/config');
define('RUNTIME_PATH', $runtime);
