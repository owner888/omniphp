<?php

declare(strict_types=1);

namespace OmniPHP\WebSocket;

use Workerman\Connection\TcpConnection;

/**
 * 通用 WebSocket 连接池与消息广播器
 *
 * 管理当前 Worker 进程内的活跃连接、UID 路由映射、以及心跳超时僵尸连接的主动回收清理。
 */
final class Broadcaster
{
    public const int DEFAULT_HEARTBEAT_TIMEOUT = 60; // 默认 60 秒无心跳判定为僵尸死连接

    /** @var array<int, TcpConnection> connection_id => connection */
    private static array $connections = [];

    /** @var array<int, int> uid => connection_id */
    private static array $uidToConnId = [];

    /**
     * 注册客户端连接
     */
    public static function register(TcpConnection $connection, ?int $uid = null): void
    {
        $connId = $connection->id;
        $connection->lastActiveTime = time();
        self::$connections[$connId] = $connection;
        if ($uid !== null && $uid > 0) {
            self::$uidToConnId[$uid] = $connId;
        }
    }

    /**
     * 绑定连接与业务用户 UID
     */
    public static function bindUid(TcpConnection $connection, int $uid): void
    {
        $connId = $connection->id;
        $connection->lastActiveTime = time();
        self::$connections[$connId] = $connection;
        self::$uidToConnId[$uid] = $connId;
    }

    /**
     * 注销连接
     */
    public static function unregister(TcpConnection $connection): void
    {
        $connId = $connection->id;
        unset(self::$connections[$connId]);

        foreach (self::$uidToConnId as $uid => $cid) {
            if ($cid === $connId) {
                unset(self::$uidToConnId[$uid]);
                break;
            }
        }
    }

    /**
     * 刷新连接活跃时间戳
     */
    public static function touch(TcpConnection $connection): void
    {
        $connection->lastActiveTime = time();
    }

    /**
     * 向全服所有在线连接广播消息
     *
     * @param array<string, mixed> $data
     */
    public static function broadcast(string $type, array $data): int
    {
        $payload = json_encode([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);

        $sentCount = 0;
        foreach (self::$connections as $conn) {
            $conn->send($payload);
            $sentCount++;
        }

        return $sentCount;
    }

    /**
     * 向指定 UID 的在线用户单播消息
     *
     * @param array<string, mixed> $data
     */
    public static function sendToUid(int $uid, string $type, array $data): bool
    {
        $connId = self::$uidToConnId[$uid] ?? null;
        if ($connId === null || !isset(self::$connections[$connId])) {
            return false;
        }

        $payload = json_encode([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);

        self::$connections[$connId]->send($payload);
        return true;
    }

    /**
     * 扫描并主动清理超时无心跳的僵尸死连接
     *
     * @return int 清理的连接数量
     */
    public static function sweep(int $timeout = self::DEFAULT_HEARTBEAT_TIMEOUT): int
    {
        $now = time();
        $victims = [];

        foreach (self::$connections as $connId => $conn) {
            $last = (int)($conn->lastActiveTime ?? 0);
            if ($last > 0 && ($now - $last) > $timeout) {
                $victims[] = $conn;
            }
        }

        foreach ($victims as $conn) {
            self::unregister($conn);
            $conn->close();
        }

        return count($victims);
    }

    /**
     * 获取当前在线连接数
     */
    public static function count(): int
    {
        return count(self::$connections);
    }

    /**
     * 获取全部在线连接
     *
     * @return array<int, TcpConnection>
     */
    public static function allConnections(): array
    {
        return self::$connections;
    }

    /**
     * 清空全部连接记录 (供测试重置)
     */
    public static function reset(): void
    {
        self::$connections = [];
        self::$uidToConnId = [];
    }
}
