<?php

namespace OmniPHP;

/**
 * Logger - 彩色日志工具类
 * 
 * 支持 verbose、debug、info、warning、error 五个日志级别
 * 级别优先级（对齐 iOS Logger.swift）：
 *   ERROR(4) > WARNING(3) > INFO(2) > DEBUG(1) > VERBOSE(0)
 * 
 * 通过 LOG_LEVEL 环境变量控制最低输出级别：
 *   LOG_LEVEL=info    → 只输出 INFO/WARNING/ERROR（生产推荐）
 *   LOG_LEVEL=debug   → 输出 DEBUG 及以上
 *   LOG_LEVEL=verbose → 全部输出（开发调试）
 * 
 * 不同级别使用不同颜色输出
 * 支持扩展引擎（如 Telegram/Webhook）
 */
class Logger
{
    // 颜色代码
    const COLOR_VERBOSE = '34'; // 蓝色
    const COLOR_DEBUG = '36';   // 青色
    const COLOR_INFO = '32';    // 绿色
    const COLOR_WARNING = '33'; // 黄色
    const COLOR_ERROR = '31';   // 红色

    // 日志级别名称
    const LEVEL_VERBOSE = 'VERBOSE';
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    // 级别优先级（对齐 iOS Logger.Level rawValue）
    const LEVEL_PRIORITY = [
        'VERBOSE' => 0,
        'DEBUG'   => 1,
        'INFO'    => 2,
        'WARNING' => 3,
        'WARN'    => 3,  // 兼容别名
        'ERROR'   => 4,
    ];

    // 是否启用日志
    private static bool $enabled = true;

    // 是否显示时间戳
    private static bool $showTimestamp = true;

    // 是否是 CLI 模式
    private static ?bool $isCli = null;

    // 最低输出级别（低于此级别的日志不输出）
    private static string $minLevel = 'VERBOSE';

    // 注册的引擎
    private static array $engines = [];

    /**
     * 初始化配置
     */
    public static function init(bool $enabled = true, bool $showTimestamp = true, bool $enableFileLog = true): void
    {
        self::$enabled = $enabled;
        self::$showTimestamp = $showTimestamp;
        self::$isCli = php_sapi_name() === 'cli';
        
        // 默认启用文件日志引擎，写入 runtime/logs
        if ($enableFileLog && !isset(self::$engines['File'])) {
            self::initFileEngine();
        }
    }
    
    /**
     * 初始化文件日志引擎
     * 为每个日志级别创建独立的日志文件
     */
    private static function initFileEngine(): void
    {
        // 确保 RUNTIME_PATH 已定义
        if (!defined('RUNTIME_PATH')) {
            define('RUNTIME_PATH', dirname(__DIR__) . '/runtime');
        }
        
        $logPath = RUNTIME_PATH . '/logs';
        
        // 确保日志目录存在
        if (!is_dir($logPath)) {
            @mkdir($logPath, 0755, true);
        }
        
        // 为每个日志级别创建独立的文件引擎
        // maxDays 由 env LOG_KEEP_DAYS 统一控制（跟 LogRotator 一致），默认 5
        $keepDays = max(1, (int)(getenv('LOG_KEEP_DAYS') ?: 5));
        if (class_exists('OmniPHP\Engines\FileEngine')) {
            foreach (['verbose', 'debug', 'info', 'warning', 'error'] as $level) {
                $engine = new \OmniPHP\Engines\FileEngine($logPath, $level);
                $engine->setMaxDays($keepDays);
                self::registerEngine($engine, 'File-' . ucfirst($level));
            }
        }
    }

    /**
     * 启用/禁用日志
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * 设置最低输出级别
     * 
     * @param string $level 级别名称：verbose|debug|info|warning|error
     */
    public static function setLevel(string $level): void
    {
        $normalized = strtoupper(trim($level));
        if (isset(self::LEVEL_PRIORITY[$normalized])) {
            self::$minLevel = $normalized;
        }
    }

    /**
     * 获取当前最低输出级别
     */
    public static function getLevel(): string
    {
        return self::$minLevel;
    }

    /**
     * 检查指定级别是否会被输出
     */
    public static function isLevelEnabled(string $level): bool
    {
        $levelPriority = self::LEVEL_PRIORITY[strtoupper($level)] ?? 0;
        $minPriority = self::LEVEL_PRIORITY[self::$minLevel] ?? 0;
        return $levelPriority >= $minPriority;
    }

    /**
     * 设置是否显示时间戳
     */
    public static function setShowTimestamp(bool $show): void
    {
        self::$showTimestamp = $show;
    }

    /**
     * 注册日志引擎
     * 
     * @param LoggerEngineInterface $engine 引擎实例
     * @param string|null $alias 别名
     */
    public static function registerEngine(LoggerEngineInterface $engine, ?string $alias = null): void
    {
        $name = $alias ?? $engine->getName();
        self::$engines[$name] = $engine;
    }

    /**
     * 移除日志引擎
     */
    public static function removeEngine(string $name): void
    {
        unset(self::$engines[$name]);
    }

    /**
     * 获取所有已注册的引擎
     */
    public static function getEngines(): array
    {
        return self::$engines;
    }

    /**
     * 通过引擎发送日志
     * 根据日志级别只发送到对应的文件引擎
     */
    private static function sendToEngines(string $level, string $message, string|array $context = []): void
    {
        foreach (self::$engines as $engineName => $engine) {
            if (!($engine instanceof LoggerEngineInterface) || !$engine->isAvailable()) {
                continue;
            }
            
            // 如果是文件引擎，只发送到对应级别的引擎
            if (strpos($engineName, 'File-') === 0) {
                $engineLevel = str_replace('File-', '', $engineName);
                if (strtoupper($engineLevel) !== strtoupper($level)) {
                    continue; // 跳过不匹配级别的文件引擎
                }
            }
            
            try {
                $engine->send($level, $message, $context);
            } catch (\Exception $e) {
                // 静默忽略引擎错误
                error_log("Logger Engine Error: " . $e->getMessage());
            }
        }
    }

    /**
     * 获取带颜色的格式化字符串
     */
    private static function format(string $level, string $message, string $color): string
    {
        $timestamp = self::$showTimestamp ? '[' . date('Y-m-d H:i:s') . '] ' : '';
        $prefix = self::getLevelPrefix($level);
        
        if (self::$isCli) {
            $coloredPrefix = "\033[{$color}m{$prefix}\033[0m";
            return $timestamp . $coloredPrefix . ' ' . $message;
        }
        
        // 非 CLI 模式返回纯文本
        return $timestamp . $prefix . ' ' . $message;
    }

    /**
     * 获取级别前缀
     */
    private static function getLevelPrefix(string $level): string
    {
        return '[' . str_pad($level, 7, ' ', STR_PAD_RIGHT) . ']';
    }

    /**
     * 输出日志
     */
    private static function log(string $level, string $message, string $color, string|array $context = []): void
    {
        if (!self::$enabled) {
            return;
        }

        // 级别过滤：低于最低级别的日志不输出
        $levelPriority = self::LEVEL_PRIORITY[$level] ?? 0;
        $minPriority = self::LEVEL_PRIORITY[self::$minLevel] ?? 0;
        if ($levelPriority < $minPriority) {
            return;
        }

        $formatted = self::format($level, $message, $color);
        
        // 添加 context 信息
        if (!empty($context)) {
            // 如果是 string 直接使用，如果是 array 才 json_encode
            $contextStr = is_array($context) 
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : $context;
            $formatted .= "\n  Context: " . $contextStr;
        }
        
        // CLI 模式输出到控制台
        if (self::$isCli) {
            echo $formatted . PHP_EOL;
        } else {
            // Web 模式记录到错误日志
            error_log(strip_tags($formatted));
        }

        // 发送到注册的引擎（文件引擎始终写入，不受级别过滤影响）
        self::sendToEngines($level, $message, $context);
    }

    /**
     * VERBOSE 级别 - 蓝色（最详细，如 stream chunk）
     */
    public static function verbose(string $message, string|array $context = []): void
    {
        self::log(self::LEVEL_VERBOSE, $message, self::COLOR_VERBOSE, $context);
    }

    /**
     * DEBUG 级别 - 青色
     */
    public static function debug(string $message, string|array $context = []): void
    {
        self::log(self::LEVEL_DEBUG, $message, self::COLOR_DEBUG, $context);
    }

    /**
     * INFO 级别 - 绿色
     */
    public static function info(string $message, string|array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, self::COLOR_INFO, $context);
    }

    /**
     * WARNING 级别 - 黄色
     */
    public static function warning(string $message, string|array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, self::COLOR_WARNING, $context);
    }

    /**
     * ERROR 级别 - 红色
     */
    public static function error(string $message, string|array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, self::COLOR_ERROR, $context);
    }

    /**
     * 记录数组/对象（调试用）
     */
    public static function dump(string $label, $data): void
    {
        if (!self::$enabled) {
            return;
        }

        $output = print_r($data, true);
        self::debug("{$label}: {$output}");
    }

    /**
     * 性能计时开始
     */
    public static function time(string $label): void
    {
        if (!self::$enabled) {
            return;
        }

        $_SERVER['LOGGER_TIME'][$label] = microtime(true);
        self::debug("Timer '{$label}' started");
    }

    /**
     * 性能计时结束并输出
     */
    public static function timeEnd(string $label): float
    {
        if (!self::$enabled || !isset($_SERVER['LOGGER_TIME'][$label])) {
            return 0;
        }

        $start = $_SERVER['LOGGER_TIME'][$label];
        $end = microtime(true);
        $duration = round(($end - $start) * 1000, 2);
        
        unset($_SERVER['LOGGER_TIME'][$label]);
        self::info("Timer '{$label}': {$duration}ms");
        
        return $duration;
    }

    /**
     * 分割线
     */
    public static function separator(string $char = '-', int $length = 50): void
    {
        if (!self::$enabled) {
            return;
        }

        $line = str_repeat($char, $length);
        self::info($line);
    }

    /**
     * 标题样式
     */
    public static function title(string $title): void
    {
        if (!self::$enabled) {
            return;
        }

        $border = str_repeat('=', 50);
        self::info('');
        self::info($border);
        self::info('  ' . $title);
        self::info($border);
        self::info('');
    }
}

// 自动初始化
Logger::init();
