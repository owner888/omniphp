<?php
/**
 * 缓存适配器 - 统一的缓存接口
 * 
 * 根据配置自动选择缓存驱动（Redis 或 File）
 * 如果 Redis 不可用，自动降级到文件缓存
 */

namespace OmniPHP\Cache;

use OmniPHP\Config;
use OmniPHP\Logger;

class Cache
{
    private static ?string $driver = null;
    private static bool $initialized = false;
    private static $fileCacheInstance = null;  // FileCache 实例
    
    /**
     * 初始化缓存驱动
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        
        // 从配置读取驱动类型
        $configDriver = Config::get('cache.driver', 'redis');
        
        // 尝试初始化配置的驱动
        if ($configDriver === 'redis') {
            Redis::init();
            
            // 检查 Redis 是否可用
            if (Redis::isConnected()) {
                self::$driver = 'redis';
                Logger::info("缓存驱动: Redis");
            } else {
                // Redis 不可用，降级到文件缓存
                Logger::warning("Redis 不可用，降级使用文件缓存");
                self::$fileCacheInstance = FileCache::factory();
                self::$driver = 'file';
            }
        } elseif ($configDriver === 'file') {
            self::$fileCacheInstance = FileCache::factory();
            self::$driver = 'file';
            Logger::info("缓存驱动: File");
        } else {
            // 默认使用文件缓存
            Logger::warning("未知的缓存驱动: {$configDriver}，使用文件缓存");
            self::$fileCacheInstance = FileCache::factory();
            self::$driver = 'file';
        }
        
        self::$initialized = true;
    }
    
    /**
     * 获取 FileCache 实例
     */
    private static function getFileCacheInstance()
    {
        if (self::$fileCacheInstance === null) {
            self::$fileCacheInstance = FileCache::factory();
        }
        return self::$fileCacheInstance;
    }
    
    /**
     * 获取当前驱动
     */
    public static function getDriver(): string
    {
        if (!self::$initialized) {
            self::init();
        }
        return self::$driver ?? 'file';
    }
    
    /**
     * 检查是否已连接/初始化
     */
    public static function isConnected(): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return match(self::$driver) {
            'redis' => Redis::isConnected(),
            'file' => true,
            default => false,
        };
    }
    
    /**
     * 获取值
     */
    public static function get(string $key): mixed
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return match(self::$driver) {
            'redis' => Redis::get($key),
            'file' => self::getFileCacheInstance()->getValue($key),
            default => null,
        };
    }
    
    /**
     * 设置值
     */
    public static function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        // 如果没有指定 TTL，使用配置的默认值
        if ($ttl === null) {
            $ttl = Config::get('cache.ttl.default', 3600);
        }
        
        return match(self::$driver) {
            'redis' => Redis::set($key, $value, $ttl),
            'file' => self::getFileCacheInstance()->setValue($key, $value, $ttl),
            default => false,
        };
    }
    
    /**
     * 删除键
     */
    public static function delete(string $key): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return match(self::$driver) {
            'redis' => Redis::delete($key),
            'file' => self::getFileCacheInstance()->deleteValue($key),
            default => false,
        };
    }
    
    /**
     * 检查键是否存在
     */
    public static function exists(string $key): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return match(self::$driver) {
            'redis' => Redis::exists($key),
            'file' => self::get($key) !== false && self::get($key) !== null,
            default => false,
        };
    }
    
    /**
     * 获取所有匹配的键
     */
    public static function keys(string $pattern): array
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return match(self::$driver) {
            'redis' => Redis::keys($pattern),
            'file' => self::getFileCacheInstance()->getKeys($pattern),
            default => [],
        };
    }
    
    /**
     * 设置键的过期时间（仅 Redis 支持）
     */
    public static function expire(string $key, int $seconds): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        if (self::$driver === 'redis') {
            return Redis::expire($key, $seconds);
        }
        
        // 文件缓存不支持单独设置过期时间
        return false;
    }
    
    /**
     * 清理过期缓存（仅文件缓存需要）
     */
    public static function gc(): int
    {
        if (!self::$initialized) {
            self::init();
        }
        
        if (self::$driver === 'file') {
            return self::getFileCacheInstance()->garbageCollect();
        }
        
        return 0;
    }
    
    /**
     * 清空所有缓存
     */
    public static function flush(): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        return match(self::$driver) {
            'redis' => Redis::instance()?->flushDB() ?? false,
            'file' => self::getFileCacheInstance()->clear(),
            default => false,
        };
    }
    
    /**
     * 生成带前缀的缓存键
     */
    public static function makeKey(string $type, string $identifier): string
    {
        $prefix = Config::get('cache.redis.prefix', '');
        return $prefix . $type . ':' . $identifier;
    }
    
    /**
     * 批量获取（优化性能）
     */
    public static function mget(array $keys): array
    {
        if (!self::$initialized) {
            self::init();
        }
        
        if (self::$driver === 'redis' && Redis::isConnected()) {
            // Redis 支持批量获取
            $instance = Redis::instance();
            if ($instance) {
                $values = $instance->mGet($keys);
                $result = [];
                foreach ($keys as $i => $key) {
                    $result[$key] = $values[$i] !== false ? json_decode($values[$i], true) : null;
                }
                return $result;
            }
        }
        
        // 文件缓存逐个获取
        $result = [];
        $instance = self::getFileCacheInstance();
        foreach ($keys as $key) {
            $result[$key] = $instance->getValue($key);
        }
        return $result;
    }
    
    /**
     * 批量设置（优化性能）
     */
    public static function mset(array $data, ?int $ttl = null): bool
    {
        if (!self::$initialized) {
            self::init();
        }
        
        if ($ttl === null) {
            $ttl = Config::get('cache.ttl.default', 3600);
        }
        
        if (self::$driver === 'redis' && Redis::isConnected()) {
            // Redis 批量设置
            $instance = Redis::instance();
            if ($instance) {
                $pipeline = $instance->multi(\Redis::PIPELINE);
                foreach ($data as $key => $value) {
                    $pipeline->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));
                }
                $pipeline->exec();
                return true;
            }
        }
        
        // 文件缓存逐个设置
        $instance = self::getFileCacheInstance();
        foreach ($data as $key => $value) {
            $instance->setValue($key, $value, $ttl);
        }
        return true;
    }
}
