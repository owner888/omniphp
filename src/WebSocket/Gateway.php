<?php

declare(strict_types=1);

namespace OmniPHP\WebSocket;

use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 通用 WebSocket 服务网关与协议驱动器
 */
class Gateway
{
    private static ?int $sweepTimerId = null;

    /**
     * 将通用网关绑定到指定的 Workerman Worker 实例
     *
     * 自动接管：
     * 1. onWorkerStart: 启动心跳超时清理定时器 (sweep)
     * 2. onWebSocketPing: 自动响应 RFC 6455 协议级 ping (0x9) 并刷新活跃时间
     * 3. onConnect / onClose: 自动维护在线连接池
     * 4. onMessage: 自动封装为 Context 并传递给业务分发闭包
     *
     * @param callable(Context): void $messageHandler
     */
    public static function bind(Worker $worker, callable $messageHandler, int $sweepInterval = 15): void
    {
        $worker->onWorkerStart = static function () use ($sweepInterval): void {
            self::initSweeper($sweepInterval);
        };

        $worker->onConnect = static function (TcpConnection $connection): void {
            Broadcaster::register($connection);
        };

        $worker->onClose = static function (TcpConnection $connection): void {
            Broadcaster::unregister($connection);
        };

        $worker->onWebSocketPing = static function (TcpConnection $connection, string $pingData): void {
            Broadcaster::touch($connection);
            $connection->send($pingData);
        };

        $worker->onMessage = static function (TcpConnection $connection, string $data) use ($messageHandler): void {
            Broadcaster::touch($connection);
            $ctx = new Context($connection, $data);
            $messageHandler($ctx);
        };
    }

    /**
     * 初始化定时扫描清理僵尸连接的任务
     */
    public static function initSweeper(int $sweepInterval = 15, int $timeout = Broadcaster::DEFAULT_HEARTBEAT_TIMEOUT): void
    {
        if (self::$sweepTimerId !== null) {
            Timer::del(self::$sweepTimerId);
            self::$sweepTimerId = null;
        }

        if (class_exists(Timer::class)) {
            self::$sweepTimerId = Timer::add($sweepInterval, static function () use ($timeout): void {
                Broadcaster::sweep($timeout);
            });
        }
    }

    /**
     * 手动触发协议级 Ping 控制帧响应
     */
    public static function handleProtocolPing(TcpConnection $connection, string $pingData): void
    {
        Broadcaster::touch($connection);
        $connection->send($pingData);
    }
}
