<?php
/**
 * Redis 客户端（同步版，支持自动重连）
 * 
 * 这是最常用的 Redis 类，用于：
 * - Session 管理
 * - 配置读取
 * - 同步查询
 * - 缓存操作
 * 
 * 特性：
 * - 自动重连：操作失败时自动重连并重试
 * - 指数退避：重连延迟逐渐增加，避免雪崩
 */

namespace OmniPHP\Cache;

use OmniPHP\Logger;
use OmniPHP\Config;

class Redis
{
    private static ?\Redis $redis = null;
    private static bool $connected = false;
    private static int $maxRetries = 3; // 最大重试次数
    private static int $retryDelay = 100000; // 初始重试延迟（微秒，100ms）
    
    /**
     * 初始化连接
     */
    public static function init(): void
    {
        if (self::$redis !== null && self::$connected) {
            return;
        }
        
        // 检查 Redis 扩展
        if (!extension_loaded('redis')) {
            Logger::warning("PHP Redis 扩展未安装，无法使用同步 Redis");
            echo "⚠️  PHP Redis 扩展未安装\n";
            echo "   安装方法: pecl install redis\n";
            echo "   或使用异步 Redis（功能受限）\n\n";
            return;
        }
        
        try {
            // 从配置读取 Redis 连接信息
            $host = Config::get('cache.redis.host', '127.0.0.1');
            $port = Config::get('cache.redis.port', 6379);
            $password = Config::get('cache.redis.password', '');
            $database = Config::get('cache.redis.database', 0);
            $timeout = Config::get('cache.redis.timeout', 5);
            
            self::$redis = new \Redis();
            
            // 连接
            $result = self::$redis->connect($host, $port, $timeout);
            
            if (!$result) {
                self::$connected = false;
                Logger::error("Redis 连接失败", [
                    'host' => $host,
                    'port' => $port,
                ]);
                return;
            }
            
            // 认证（如果有密码）
            if ($password) {
                $authResult = self::$redis->auth($password);
                if (!$authResult) {
                    self::$connected = false;
                    Logger::error("Redis 密码认证失败，请检查 REDIS_PASSWORD 配置", [
                        'host' => $host,
                        'port' => $port,
                    ]);
                    return;
                }
            }
            
            // 选择数据库
            if ($database > 0) {
                self::$redis->select($database);
            }
            
            // 测试连接
            $pingResult = self::$redis->ping();
            // PHP Redis 扩展可能返回 true, '+PONG', 或 'PONG'
            if ($pingResult !== true && $pingResult !== '+PONG' && $pingResult !== 'PONG') {
                self::$connected = false;
                Logger::error("Redis PING 验证失败", [
                    'ping_result' => $pingResult,
                    'ping_type' => gettype($pingResult),
                    'expected' => 'true/PONG',
                ]);
                return;
            }
            
            self::$connected = true;
            
            Logger::debug("同步 Redis 连接成功", [
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'has_password' => !empty($password),
                'type' => 'sync',
            ]);
            
        } catch (\RedisException $e) {
            self::$connected = false;
            
            Logger::error("同步 Redis 连接失败: " . $e->getMessage(), [
                'host' => Config::get('cache.redis.host', '127.0.0.1'),
                'port' => Config::get('cache.redis.port', 6379),
                'type' => 'sync',
            ]);
        }
    }
    
    /**
     * 重连（强制重新建立连接）
     */
    private static function reconnect(): bool
    {
        // 关闭旧连接
        self::$connected = false;
        if (self::$redis) {
            try {
                self::$redis->close();
            } catch (\RedisException $e) {
                // 忽略关闭错误
            }
            self::$redis = null;
        }
        
        // 重新初始化
        self::init();
        
        return self::$connected;
    }
    
    /**
     * 执行带自动重连的操作
     * 
     * 注意：Redis Broken pipe 产生的是 PHP Notice 而非 RedisException，
     * 因此需要通过 set_error_handler 将其转化为异常来触发重连。
     */
    private static function executeWithRetry(callable $operation, string $operationName, array $context = []): mixed
    {
        $retries = 0;
        $delay = self::$retryDelay;
        
        while ($retries <= self::$maxRetries) {
            try {
                // 确保已连接
                if (!self::isConnected()) {
                    self::init();
                }
                
                // 如果还是没连接，尝试重连
                if (!self::isConnected()) {
                    throw new \RedisException("Redis 未连接");
                }
                
                // 临时错误处理器：将 Redis 的 Notice/Warning 转为异常
                // Broken pipe 会产生 Notice 而非 RedisException
                set_error_handler(function($errno, $errstr) {
                    if (stripos($errstr, 'Redis') !== false || 
                        stripos($errstr, 'Broken pipe') !== false ||
                        stripos($errstr, 'Connection lost') !== false ||
                        stripos($errstr, 'went away') !== false) {
                        throw new \RedisException($errstr, $errno);
                    }
                    return false; // 非 Redis 错误继续使用默认处理
                });
                
                // 执行操作
                $result = $operation();
                
                // 恢复默认错误处理
                restore_error_handler();
                
                // 检查 Redis 内部错误
                if (self::$redis && ($lastError = self::$redis->getLastError())) {
                    self::$redis->clearLastError();
                    throw new \RedisException($lastError);
                }
                
                return $result;
                
            } catch (\RedisException $e) {
                // 确保恢复默认错误处理
                restore_error_handler();
                
                $retries++;
                
                Logger::warning("Redis {$operationName} 失败，尝试重连 (重试 {$retries}/" . self::$maxRetries . ")", array_merge($context, [
                    'error' => $e->getMessage(),
                    'retry' => $retries,
                ]));
                
                // 标记为未连接
                self::$connected = false;
                
                // 如果还有重试次数，等待后重连
                if ($retries <= self::$maxRetries) {
                    usleep($delay);
                    $delay *= 2; // 指数退避
                    
                    // 尝试重连
                    if (!self::reconnect()) {
                        Logger::error("Redis 重连失败 (重试 {$retries}/" . self::$maxRetries . ")", $context);
                        // 继续重试
                        continue;
                    }
                } else {
                    // 超过最大重试次数
                    Logger::error("Redis {$operationName} 最终失败，已达最大重试次数", array_merge($context, [
                        'error' => $e->getMessage(),
                        'retries' => $retries,
                    ]));
                    throw $e;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 获取 Redis 实例
     */
    public static function instance(): ?\Redis
    {
        if (!self::$connected) {
            self::init();
        }
        return self::$redis;
    }
    
    /**
     * 是否已连接
     */
    public static function isConnected(): bool
    {
        return self::$connected && self::$redis !== null;
    }
    
    /**
     * 获取值（同步，支持自动重连）
     */
    public static function get(string $key): mixed
    {
        return self::executeWithRetry(
            function() use ($key) {
                $value = self::$redis->get($key);
                return $value !== false ? json_decode($value, true) : null;
            },
            'GET',
            ['key' => $key]
        );
    }
    
    /**
     * 设置值（同步，支持自动重连）
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return self::executeWithRetry(
            function() use ($key, $value, $ttl) {
                return self::$redis->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));
            },
            'SET',
            ['key' => $key, 'ttl' => $ttl]
        ) ?? false;
    }
    
    /**
     * 删除键（同步，支持自动重连）
     */
    public static function delete(string $key): bool
    {
        return self::executeWithRetry(
            function() use ($key) {
                return self::$redis->del($key) > 0;
            },
            'DELETE',
            ['key' => $key]
        ) ?? false;
    }
    
    /**
     * 设置键的过期时间（秒，支持自动重连）
     */
    public static function expire(string $key, int $seconds): bool
    {
        return self::executeWithRetry(
            function() use ($key, $seconds) {
                return self::$redis->expire($key, $seconds);
            },
            'EXPIRE',
            ['key' => $key, 'seconds' => $seconds]
        ) ?? false;
    }
    
    /**
     * 检查键是否存在（同步，支持自动重连）
     */
    public static function exists(string $key): bool
    {
        return self::executeWithRetry(
            function() use ($key) {
                return self::$redis->exists($key) > 0;
            },
            'EXISTS',
            ['key' => $key]
        ) ?? false;
    }
    
    /**
     * 获取所有匹配的键（同步，支持自动重连）
     */
    public static function keys(string $pattern): array
    {
        return self::executeWithRetry(
            function() use ($pattern) {
                return self::$redis->keys($pattern) ?: [];
            },
            'KEYS',
            ['pattern' => $pattern]
        ) ?? [];
    }
    
    /**
     * 魔术方法：代理所有未定义的静态方法到 Redis 实例
     * 
     * 这样可以调用任何 Redis 原生方法，并且都享有自动重连的保护
     * 例如：Redis::rPush(), Redis::multi(), Redis::exec() 等
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::executeWithRetry(
            function() use ($method, $arguments) {
                return self::$redis->$method(...$arguments);
            },
            strtoupper($method),
            ['method' => $method, 'args_count' => count($arguments)]
        );
    }
    
    /**
     * 关闭连接
     */
    public static function close(): void
    {
        if (self::$redis) {
            try {
                self::$redis->close();
            } catch (\RedisException $e) {
                // 忽略关闭错误
            }
            self::$redis = null;
            self::$connected = false;
        }
    }
}
