<?php
/**
 * Session 管理（直接使用 Redis）
 * 
 * 直接从 Redis 实时读取，Redis 足够快，无需内存缓存。
 * 
 * 注意：不再做 Redis::isConnected() 提前判断！
 * Redis 类本身有 executeWithRetry + 自动重连机制，
 * 如果在 Session 层提前 return false/null，会导致 Redis 断线时
 * 用户被莫名踢出（因为 Session::get 返回 null → AuthMiddleware 判定未登录）。
 */

namespace OmniPHP\Http;

use OmniPHP\Cache\Redis;
use OmniPHP\Logger;

class Session
{
    protected static string $sessionKey = 'session:';
    protected static int $lifetime = 7200; // 2小时
    
    /**
     * 生成 Session ID
     */
    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * 从 Cookie 获取 Session ID
     */
    public static function getSessionId($request): ?string
    {
        $cookie = $request->cookie('PHPSESSID');
        return $cookie ?: null;
    }
    
    /**
     * 设置 Session 数据（直接写入 Redis）
     */
    public static function set(string $sessionId, string $key, mixed $value): bool
    {
        try {
            $redisKey = self::$sessionKey . $sessionId;
            $session = Redis::get($redisKey);
            
            if (!$session) {
                $session = [
                    'data' => [],
                    'created_at' => time(),
                    'updated_at' => time(),
                ];
            }
            
            // 更新数据
            $session['data'][$key] = $value;
            $session['updated_at'] = time();
            
            // 写入 Redis 并设置过期时间
            return Redis::set($redisKey, $session, self::$lifetime);
        } catch (\Throwable $e) {
            Logger::error("Session::set 失败", [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * 获取 Session 数据（直接从 Redis 读取）
     * 
     * 利用 Redis 类的 executeWithRetry 自动重连机制，
     * 不再提前判断 isConnected()，避免断线后误判为未登录。
     */
    public static function get(string $sessionId, ?string $key = null): mixed
    {
        try {
            $redisKey = self::$sessionKey . $sessionId;
            $session = Redis::get($redisKey);
            
            if (!$session) {
                return $key === null ? [] : null;
            }
            
            // 每次读取时自动刷新过期时间
            Redis::expire($redisKey, self::$lifetime);
            
            if ($key === null) {
                return $session['data'] ?? [];
            }
            
            return $session['data'][$key] ?? null;
        } catch (\Throwable $e) {
            Logger::error("Session::get 失败", [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $key === null ? [] : null;
        }
    }
    
    /**
     * 删除 Session 数据
     */
    public static function delete(string $sessionId, ?string $key = null): bool
    {
        try {
            $redisKey = self::$sessionKey . $sessionId;
            
            if ($key === null) {
                // 删除整个 session
                return Redis::delete($redisKey);
            }
            
            // 删除特定键
            $session = Redis::get($redisKey);
            if ($session && isset($session['data'][$key])) {
                unset($session['data'][$key]);
                $session['updated_at'] = time();
                return Redis::set($redisKey, $session, self::$lifetime);
            }
            
            return true;
        } catch (\Throwable $e) {
            Logger::error("Session::delete 失败", [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * 检查 Session 是否存在
     */
    public static function exists(string $sessionId): bool
    {
        try {
            $redisKey = self::$sessionKey . $sessionId;
            return Redis::exists($redisKey);
        } catch (\Throwable $e) {
            Logger::error("Session::exists 失败", [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * 刷新 Session 过期时间
     */
    public static function touch(string $sessionId): bool
    {
        try {
            $redisKey = self::$sessionKey . $sessionId;
            $session = Redis::get($redisKey);
            
            if ($session) {
                $session['updated_at'] = time();
                return Redis::set($redisKey, $session, self::$lifetime);
            }
            
            return false;
        } catch (\Throwable $e) {
            Logger::error("Session::touch 失败", [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * 清理过期 Session（Redis 自动处理，此方法保留用于统计）
     */
    public static function gc(): int
    {
        // Redis 会自动清理过期的 key，无需手动处理
        // 此方法保留用于兼容性
        return 0;
    }
    
    /**
     * 获取统计信息
     */
    public static function stats(): array
    {
        try {
            // 统计 Redis 中的 session 数量
            $pattern = self::$sessionKey . '*';
            $keys = Redis::keys($pattern);
            
            return [
                'total' => count($keys),
                'lifetime' => self::$lifetime,
                'storage' => 'redis',
            ];
        } catch (\Throwable $e) {
            return [
                'total' => 0,
                'lifetime' => self::$lifetime,
                'storage' => 'redis (error: ' . $e->getMessage() . ')',
            ];
        }
    }
}
