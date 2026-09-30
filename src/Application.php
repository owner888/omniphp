<?php

namespace OmniPHP;

use Workerman\Worker;

/**
 * 应用启动器：把每个入口（server.php / worker.php / cli 脚本）都要做的一套初始化收在一起
 *
 * 用法（bootstrap.php）：
 *   require __DIR__ . '/vendor/autoload.php';
 *   Application::boot(__DIR__, [
 *       'lang' => ['default' => 'en', 'fallback' => 'en', 'lang_dir' => __DIR__ . '/app/Lang', 'always_load' => ['common']],
 *   ]);
 *
 * boot() 依次做：定义 BASE_PATH / CONFIG_PATH / RUNTIME_PATH（已定义则跳过）→ 读 .env →
 * 时区（APP_TIMEZONE）→ Logger 级别（LOG_LEVEL）→ Lang::init（给了 lang 才做）→ 装 CrashHandler。
 * 业务钩子（Logger::registerEngine / RedisQueue::setDlqListener / Router::setExceptionHandler）
 * 由应用在 boot() 之后自己注册。
 */
final class Application
{
    private static bool $booted = false;

    /**
     * @param array{
     *   config_path?: string,
     *   runtime_path?: string,
     *   env_file?: string,
     *   lang?: array,
     *   crash_handler?: bool,
     * } $options
     */
    public static function boot(string $basePath, array $options = []): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $basePath = rtrim($basePath, '/');
        defined('BASE_PATH')    || define('BASE_PATH', $basePath);
        defined('CONFIG_PATH')  || define('CONFIG_PATH', $options['config_path'] ?? $basePath . '/config');
        defined('RUNTIME_PATH') || define('RUNTIME_PATH', $options['runtime_path'] ?? $basePath . '/runtime');

        self::loadEnv($options['env_file'] ?? $basePath . '/.env');

        date_default_timezone_set(getenv('APP_TIMEZONE') ?: date_default_timezone_get());

        Logger::setLevel(getenv('LOG_LEVEL') ?: 'info');

        if (!empty($options['lang'])) {
            Lang::init($options['lang']);
        }

        if ($options['crash_handler'] ?? true) {
            CrashHandler::install();
        }
    }

    /**
     * Workerman 进程级配置：pid / log / stdout 落到 RUNTIME_PATH，event loop 切 Fiber（Revolt）
     *
     * 必须在任何 Worker 实例化之前调用；fork 之后 child 继承 driver。
     * 生成的文件名：{name}.pid、logs/{name}.log、logs/{name}_stdout.log。
     */
    public static function workerman(string $name = 'workerman', bool $fiber = true): void
    {
        if (!defined('RUNTIME_PATH')) {
            throw new \RuntimeException('Application::workerman() requires RUNTIME_PATH; call Application::boot() first');
        }
        if ($fiber) {
            Worker::$eventLoopClass = \Workerman\Events\Fiber::class;
        }
        Worker::$pidFile    = RUNTIME_PATH . "/{$name}.pid";
        Worker::$logFile    = RUNTIME_PATH . "/logs/{$name}.log";
        Worker::$stdoutFile = RUNTIME_PATH . "/logs/{$name}_stdout.log";
    }

    /**
     * 读 .env：KEY=VALUE 一行一个，# 开头是注释，值两侧的引号会去掉；已存在的环境变量不覆盖
     */
    private static function loadEnv(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim(trim($value), '"\'');

                if (!getenv($key)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}
