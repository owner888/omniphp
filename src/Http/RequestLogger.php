<?php
/**
 * HTTP 请求日志记录器
 * 
 * 类似 Golang Gin 的请求日志格式：
 * [HTTP] 2025/01/25 - 12:00:00 | 200 |    1.234ms |  192.168.1.1 | GET     "/api/users"
 */

namespace OmniPHP\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class RequestLogger
{
    /**
     * ANSI 颜色代码
     */
    private const COLOR_RESET = "\033[0m";
    private const COLOR_GREEN = "\033[32m";
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_RED = "\033[31m";
    private const COLOR_CYAN = "\033[36m";
    private const COLOR_GRAY = "\033[90m";
    private const COLOR_WHITE = "\033[97m";
    private const COLOR_BLUE = "\033[34m";
    
    /**
     * 记录请求开始
     * 
     * @param Request $request HTTP 请求对象
     * @return float 开始时间（微秒）
     */
    public static function start(Request $request): float
    {
        return microtime(true);
    }
    
    /**
     * 记录请求结束并输出日志
     * 
     * @param Request $request HTTP 请求对象
     * @param int $statusCode HTTP 状态码
     * @param float $startTime 开始时间
     * @param TcpConnection|null $connection 连接对象（用于获取客户端 IP）
     */
    public static function end(Request $request, int $statusCode, float $startTime, ?TcpConnection $connection = null): void
    {
        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // 转换为毫秒
        
        $method = $request->method();
        $path = $request->path();
        $clientIp = self::getClientIp($request, $connection);
        $countryCode = self::getCountryCode($request);
        $timestamp = date('Y/m/d - H:i:s');
        
        // IP + 国家码
        $clientInfo = $countryCode ? "{$clientIp} ({$countryCode})" : $clientIp;
        
        // 根据状态码选择颜色
        $statusColor = self::getStatusColor($statusCode);
        
        // 根据响应时间选择颜色
        $durationColor = self::getDurationColor($duration);
        
        // 根据 HTTP 方法选择颜色
        $methodColor = self::getMethodColor($method);
        
        // 格式化输出
        $log = sprintf(
            "%s[HTTP]%s %s%s%s | %s%3d%s | %s%8s%s | %s%-20s%s | %s%-7s%s %s\"%s\"%s",
            self::COLOR_CYAN,      // [HTTP]
            self::COLOR_RESET,
            self::COLOR_GRAY,      // 时间戳
            $timestamp,
            self::COLOR_RESET,
            $statusColor,          // 状态码
            $statusCode,
            self::COLOR_RESET,
            $durationColor,        // 响应时间
            self::formatDuration($duration),
            self::COLOR_RESET,
            self::COLOR_WHITE,     // 客户端 IP + 国家码
            $clientInfo,
            self::COLOR_RESET,
            $methodColor,          // HTTP 方法
            $method,
            self::COLOR_RESET,
            self::COLOR_WHITE,     // 路径
            $path,
            self::COLOR_RESET
        );
        
        echo $log . "\n";
    }
    
    /**
     * 获取客户端 IP
     */
    private static function getClientIp(Request $request, ?TcpConnection $connection): string
    {
        // 尝试从 X-Forwarded-For 头获取真实 IP
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor) {
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }
        
        // 尝试从 X-Real-IP 头获取
        $realIp = $request->header('X-Real-IP');
        if ($realIp) {
            return $realIp;
        }
        
        // 从连接对象获取
        if ($connection) {
            return $connection->getRemoteIp();
        }
        
        return 'unknown';
    }
    
    /**
     * 获取客户端国家码（从 nginx X-Real-Country-Short 头获取）
     */
    private static function getCountryCode(Request $request): string
    {
        return $request->header('X-Real-Country-Short') ?? '';
    }
    
    /**
     * 根据状态码返回颜色
     */
    private static function getStatusColor(int $statusCode): string
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return self::COLOR_GREEN;  // 2xx: 绿色
        } elseif ($statusCode >= 300 && $statusCode < 400) {
            return self::COLOR_CYAN;   // 3xx: 青色
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            return self::COLOR_YELLOW; // 4xx: 黄色
        } else {
            return self::COLOR_RED;    // 5xx: 红色
        }
    }
    
    /**
     * 根据响应时间返回颜色
     */
    private static function getDurationColor(float $duration): string
    {
        if ($duration < 100) {
            return self::COLOR_GREEN;  // < 100ms: 绿色
        } elseif ($duration < 500) {
            return self::COLOR_YELLOW; // 100-500ms: 黄色
        } else {
            return self::COLOR_RED;    // > 500ms: 红色
        }
    }
    
    /**
     * 根据 HTTP 方法返回颜色
     */
    private static function getMethodColor(string $method): string
    {
        return match ($method) {
            'GET' => self::COLOR_BLUE,
            'POST' => self::COLOR_GREEN,
            'PUT' => self::COLOR_YELLOW,
            'DELETE' => self::COLOR_RED,
            'PATCH' => self::COLOR_CYAN,
            default => self::COLOR_WHITE,
        };
    }
    
    /**
     * 格式化响应时间
     */
    private static function formatDuration(float $duration): string
    {
        if ($duration < 1) {
            return sprintf('%.3fms', $duration);
        } elseif ($duration < 1000) {
            return sprintf('%.2fms', $duration);
        } else {
            return sprintf('%.2fs', $duration / 1000);
        }
    }
    
    /**
     * 简化版日志（不带颜色，用于文件日志）
     */
    public static function logToFile(Request $request, int $statusCode, float $duration, ?TcpConnection $connection = null): void
    {
        $method = $request->method();
        $path = $request->path();
        $clientIp = self::getClientIp($request, $connection);
        $countryCode = self::getCountryCode($request);
        $timestamp = date('Y-m-d H:i:s');
        
        // IP + 国家码
        $clientInfo = $countryCode ? "{$clientIp} ({$countryCode})" : $clientIp;
        
        $log = sprintf(
            "[%s] %s %3d %8s %-20s %-7s \"%s\"",
            $timestamp,
            'HTTP',
            $statusCode,
            self::formatDuration($duration),
            $clientInfo,
            $method,
            $path
        );
        
        // 可以在这里添加写入文件的逻辑
        // file_put_contents('/path/to/access.log', $log . "\n", FILE_APPEND);
    }
}
