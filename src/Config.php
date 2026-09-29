<?php

namespace OmniPHP;

/**
 * 配置管理类
 * 
 * 用法：
 * Config::get('queue.redis.host')
 * Config::get('queue.streams.default')
 * Config::get('app.debug', false) // 带默认值
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    /**
     * 加载所有配置文件
     */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $configPath = CONFIG_PATH;
        
        // 自动扫描 config 目录下所有 .php 文件
        $files = glob($configPath . '/*.php');
        
        foreach ($files as $file) {
            $key = basename($file, '.php');
            self::$config[$key] = require $file;
        }

        self::$loaded = true;
    }

    /**
     * 获取配置值
     * 
     * @param string $key 配置键，支持点号分隔，如 'queue.redis.host'
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::load();

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 设置配置值
     * 
     * @param string $key 配置键
     * @param mixed $value 配置值
     */
    public static function set(string $key, $value): void
    {
        self::load();

        $keys = explode('.', $key);
        $config = &self::$config;

        foreach ($keys as $k) {
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    /**
     * 检查配置是否存在
     */
    public static function has(string $key): bool
    {
        self::load();

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * 获取所有配置
     */
    public static function all(): array
    {
        self::load();
        return self::$config;
    }

    /**
     * 清空配置缓存（主要用于测试）
     */
    public static function clear(): void
    {
        self::$config = [];
        self::$loaded = false;
    }
}
