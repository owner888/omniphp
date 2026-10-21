<?php

declare(strict_types=1);

namespace OmniPHP\WebSocket;

use Workerman\Connection\TcpConnection;

/**
 * WebSocket 请求与事件上下文 (对标 Go Gin 的 Context 架构模式)
 *
 * 封装 WebSocket 全双工交互周期的底层连接、报文载荷、会话绑定与应答通道，
 * 实现业务 Handler 与底层 Socket 彻底解耦，并原生支持纯内存极速单元测试 Mock。
 */
class Context
{
    /** @var array<string, mixed> */
    protected array $data = [];
    /** @var array<string, mixed> */
    protected array $attributes = [];
    public readonly float $timestamp;

    /** @var list<string> 测试捕获的出站消息日志 */
    public array $sentMessages = [];

    /**
     * @param array<string, mixed> $parsedData
     */
    public function __construct(
        public readonly ?TcpConnection $connection = null,
        public readonly string $raw = '',
        array $parsedData = [],
        protected ?int $mockUid = null
    ) {
        $this->timestamp = microtime(true);
        $this->data = !empty($parsedData) ? $parsedData : (json_decode($raw, true) ?: []);
    }

    /**
     * 极速单元测试 / 纯内存 Mock 实例
     *
     * @param array<string, mixed> $payload
     */
    public static function mock(array $payload = [], ?int $uid = null): static
    {
        return new static(
            connection: null,
            raw: json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '',
            parsedData: $payload,
            mockUid: $uid
        );
    }

    /**
     * 获取事件动作名称 (支持 action 或 type 字段)
     */
    public function action(): string
    {
        return (string)($this->data['action'] ?? $this->data['type'] ?? '');
    }

    /**
     * 获取载荷入参
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 获取全部载荷数据
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * 获取当前连接绑定的用户 UID
     */
    public function uid(): ?int
    {
        if ($this->mockUid !== null) {
            return $this->mockUid;
        }

        if ($this->connection === null) {
            return null;
        }

        /** @var int|null */
        return $this->connection->uid ?? null;
    }

    /**
     * 绑定用户 UID 到当前连接并注册至广播器
     */
    public function bindUid(int $uid): static
    {
        $this->mockUid = $uid;
        if ($this->connection !== null) {
            $this->connection->uid = $uid;
            Broadcaster::bindUid($this->connection, $uid);
        }
        return $this;
    }

    /**
     * 向当前连接的客户端单发结构化响应
     *
     * @param array<string, mixed> $data
     */
    public function emit(string $type, array $data = []): bool
    {
        $payload = json_encode([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);

        if ($this->connection !== null) {
            $this->connection->send($payload);
            return true;
        }

        $this->sentMessages[] = $payload ?: '';
        return true;
    }

    /**
     * 发送标准错误信息给当前客户端
     */
    public function error(string $message, int $code = -1): bool
    {
        return $this->emit('error', [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * 向全服在线客户端发起广播
     *
     * @param array<string, mixed> $data
     */
    public function broadcast(string $type, array $data = []): int
    {
        return Broadcaster::broadcast($type, $data);
    }

    /**
     * 上下文属性传递
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function connection(): ?TcpConnection
    {
        return $this->connection;
    }
}
