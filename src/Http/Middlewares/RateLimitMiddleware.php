<?php
/**
 * 限流中间件
 * 
 * 基于 IP 地址的请求频率限制
 */

namespace OmniPHP\Http\Middlewares;

use OmniPHP\Http\Middleware;
use OmniPHP\Http\Context;
use OmniPHP\Cache\Redis;
use Workerman\Protocols\Http\Response;

class RateLimitMiddleware implements Middleware
{
    private int $maxRequests;
    private int $windowSeconds;
    private string $prefix;
    
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, string $prefix = 'rate_limit')
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->prefix = $prefix;
    }
    
    public function handle(Context $ctx, callable $next): mixed
    {
        $ip = $ctx->ip();
        $key = "{$this->prefix}:{$ip}";

        // INCREX（需要 Redis >= 8.8）：计数 + 上限 + 窗口过期，单命令原子完成，
        // 返回 [新计数, 实际增量]，实际增量为 0 = 已到上限。
        // ENX = TTL 只在 key 新建时设置，后续请求不重置窗口。
        // 取代旧的 get→判断→set 实现，旧实现有两个 bug：
        //   1) get 与 set 之间非原子，并发请求丢计数；
        //   2) 每次 set 都重置 TTL，流量不断时窗口永不过期，计数无限累积。
        // phpredis 尚无原生方法，走 rawCommand；Redis < 8.8 会因 unknown command
        // 抛 RedisException（故意不兜底：降级回旧版 redis 必须立刻暴露）。
        $reply = Redis::rawCommand(
            'INCREX', $key,
            'BYINT', '1',
            'UBOUND', (string) $this->maxRequests,
            'EX', (string) $this->windowSeconds,
            'ENX'
        );
        $count = (int) $reply[0];

        // 检查是否超过限制
        if ((int) $reply[1] === 0) {
            $retryAfter = max(1, (int) Redis::ttl($key)); // 剩余窗口，而非整窗
            return $ctx->error(
                "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                429,
                [
                    'limit' => $this->maxRequests,
                    'window' => $this->windowSeconds,
                    'retry_after' => $retryAfter,
                ]
            );
        }

        // 继续处理请求
        $result = $next($ctx);
        
        // 添加限流头信息
        if (is_array($result)) {
            $ctx->connection()->send(new Response(200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-RateLimit-Limit' => (string) $this->maxRequests,
                'X-RateLimit-Remaining' => (string) ($this->maxRequests - $count),
                'X-RateLimit-Reset' => (string) (time() + $this->windowSeconds), // 上界估算（精确值需多一次 TTL 查询，不值）
            ], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)));
            return null;
        }

        if ($result instanceof Response) {
            $result->header('X-RateLimit-Limit', (string) $this->maxRequests);
            $result->header('X-RateLimit-Remaining', (string) max(0, $this->maxRequests - $count));
            $result->header('X-RateLimit-Reset', (string) (time() + $this->windowSeconds));
            return $result;
        }
        
        return $result;
    }
}
