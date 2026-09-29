<?php
namespace OmniPHP\Cache;

use OmniPHP\Config;
use OmniPHP\Logger;

/**
 * 缓存服务（业务逻辑层）
 * 
 * 只保留对话历史管理等业务逻辑
 * 使用同步 Redis
 * 
 * 注意：不再做 Redis::isConnected() 提前判断！
 * Redis 类本身有 executeWithRetry + 自动重连机制，
 * 如果在业务层提前 return，会导致 Redis 短暂断线时对话历史静默丢失。
 */
class CacheService
{
    // ===================================
    // 对话历史管理
    // ===================================
    
    private static int $chatHistoryTTL = 3600;
    private static int $maxHistoryLength = 20;
    
    /**
     * 获取对话上下文（同步版本）
     */
    public static function getChatContext(string $chatId, callable $callback): void
    {
        if (empty($chatId)) {
            $callback(['summary' => null, 'messages' => [], 'total_rounds' => 0]);
            return;
        }
        
        try {
            $historyKey = Config::get('cache.redis.prefix', '') . 'chat:' . $chatId;
            $summaryKey = Config::get('cache.redis.prefix', '') . 'chat_summary:' . $chatId;
            
            // 同步获取摘要和历史
            $summary = Redis::get($summaryKey);
            $messages = Redis::get($historyKey) ?: [];
            $totalRounds = count($messages) / 2 + ($summary ? ($summary['rounds_summarized'] ?? 0) : 0);
            
            $callback([
                'summary' => $summary,
                'messages' => $messages,
                'total_rounds' => (int)$totalRounds
            ]);
        } catch (\Throwable $e) {
            Logger::error("CacheService::getChatContext 失败", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $callback(['summary' => null, 'messages' => [], 'total_rounds' => 0]);
        }
    }
    
    /**
     * 添加消息到对话历史
     */
    public static function addToChatHistory(string $chatId, array $message): void
    {
        if (empty($chatId)) return;
        
        try {
            $key = Config::get('cache.redis.prefix', '') . 'chat:' . $chatId;
            $history = Redis::get($key) ?: [];
            $history[] = $message;
            
            // 限制历史长度
            if (count($history) > self::$maxHistoryLength * 2) {
                $history = array_slice($history, -self::$maxHistoryLength * 2);
            }
            
            Redis::set($key, $history, self::$chatHistoryTTL);
        } catch (\Throwable $e) {
            Logger::error("CacheService::addToChatHistory 失败", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 清空对话历史
     */
    public static function clearChatHistory(string $chatId): void
    {
        if (empty($chatId)) return;
        
        try {
            Redis::delete(Config::get('cache.redis.prefix', '') . 'chat:' . $chatId);
            Redis::delete(Config::get('cache.redis.prefix', '') . 'chat_summary:' . $chatId);
        } catch (\Throwable $e) {
            Logger::error("CacheService::clearChatHistory 失败", [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 生成缓存键
     */
    public static function makeKey(string $type, string $input): string
    {
        return Config::get('cache.redis.prefix', '') . $type . ':' . md5($input);
    }
}
