<?php

namespace OmniPHP\Queue;

use Exception;
use OmniPHP\Cache\Redis;
use OmniPHP\Config;
use OmniPHP\Logger;

class RedisQueue
{
    private string $queueType;
    private string $streamName;
    private string $streamGroup;
    private string $consumerName;
    private int $retryTimes;
    private int $retryDelay;
    private string $dlqStream;
    private int $readCount;
    private int $readBlockMs;
    private int $claimIdleMs;
    private int $claimCount;
    private int $streamMaxLen;

    /** 任务进入死信队列后的应用层回调 fn(array $task, string $error, string $queueType): void，由 bootstrap 注册 */
    private static $dlqListener = null;

    public static function setDlqListener(?callable $listener): void
    {
        self::$dlqListener = $listener;
    }

    public function __construct(string $queueType = 'default')
    {
        $this->queueType = $queueType;

        Redis::init();
        if (!Redis::isConnected()) {
            throw new Exception('Redis is not connected. Queue system requires Redis.');
        }

        $streamConfig = Config::get("queue.streams.{$queueType}") ?? Config::get('queue.streams.default', []);

        $this->streamName = (string)($streamConfig['name'] ?? ('stream:' . $queueType));
        $this->streamGroup = (string)($streamConfig['group'] ?? ('group:' . $queueType));
        $this->retryTimes = (int)($streamConfig['retry_times'] ?? 3);
        $this->retryDelay = (int)($streamConfig['retry_delay'] ?? 5);

        $consumerPrefix = (string)Config::get('queue.streams.consumer_prefix', 'consumer');
        $this->consumerName = $consumerPrefix . ':' . getmypid() . ':' . $queueType;

        $this->readCount = (int)Config::get('queue.streams.read_count', 1);
        $this->readBlockMs = (int)Config::get('queue.streams.read_block_ms', 3000);
        $this->claimIdleMs = (int)Config::get('queue.streams.claim_idle_ms', 60000);
        $this->claimCount = (int)Config::get('queue.streams.claim_count', 20);
        $this->streamMaxLen = (int)Config::get('queue.streams.maxlen', 100000);
        $this->dlqStream = (string)Config::get('queue.streams.dlq_stream', 'stream:failed');

        $this->ensureGroup();
    }

    public function getMode(): string
    {
        return 'streams';
    }

    public function getQueueType(): string
    {
        return $this->queueType;
    }

    public function getConsumerName(): string
    {
        return $this->consumerName;
    }

    public function getStreamName(): string
    {
        return $this->streamName;
    }

    public function getStreamGroup(): string
    {
        return $this->streamGroup;
    }

    private function normalizeTask(string $job, array $data = [], int $priority = 0): array
    {
        return [
            'id' => $this->generateTaskId(),
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'max_attempts' => $this->retryTimes,
            'created_at' => time(),
            'priority' => $priority,
        ];
    }

    public function push(string $job, array $data = [], int $priority = 0): bool
    {
        $task = $this->normalizeTask($job, $data, $priority);
        return $this->pushTask($task);
    }

    private function pushTask(array $task): bool
    {
        $fields = [
            'payload' => json_encode($task, JSON_UNESCAPED_UNICODE),
            'job' => (string)($task['job'] ?? ''),
            'attempts' => (string)($task['attempts'] ?? 0),
            'created_at' => (string)($task['created_at'] ?? time()),
        ];

        if ($this->streamMaxLen > 0) {
            $result = Redis::xAdd($this->streamName, '*', $fields, $this->streamMaxLen, true);
        } else {
            $result = Redis::xAdd($this->streamName, '*', $fields);
        }

        return $result !== false;
    }

    public function pushBatch(array $jobs): int
    {
        $success = 0;

        foreach ($jobs as $job) {
            $jobName = (string)($job['job'] ?? '');
            if ($jobName === '') {
                continue;
            }

            if ($this->push($jobName, (array)($job['data'] ?? []), (int)($job['priority'] ?? 0))) {
                $success++;
            }
        }

        return $success;
    }

    public function pushDelayed(string $job, array $data, int $delay): bool
    {
        $task = $this->normalizeTask($job, $data);
        return $this->pushDelayedTask($task, $delay);
    }

    private function pushDelayedTask(array $task, int $delay): bool
    {
        $task['execute_at'] = time() + max(0, $delay);

        $delayedQueue = (string)Config::get('queue.streams.delayed', 'stream:delayed');
        $score = (int)$task['execute_at'];

        return Redis::zAdd($delayedQueue, $score, json_encode($task, JSON_UNESCAPED_UNICODE)) !== false;
    }

    public function pop(int $timeout = 0): ?array
    {
        $block = $timeout > 0 ? $timeout * 1000 : $this->readBlockMs;

        $result = Redis::xReadGroup(
            $this->streamGroup,
            $this->consumerName,
            [$this->streamName => '>'],
            $this->readCount,
            $block
        );

        if (!is_array($result) || !isset($result[$this->streamName]) || empty($result[$this->streamName])) {
            return null;
        }

        foreach ($result[$this->streamName] as $messageId => $fields) {
            $task = $this->decodeStreamTask((string)$messageId, (array)$fields);
            if ($task !== null) {
                return $task;
            }
        }

        return null;
    }

    public function popMulti(int $count = 10): array
    {
        $result = Redis::xReadGroup(
            $this->streamGroup,
            $this->consumerName,
            [$this->streamName => '>'],
            max(1, $count),
            1
        );

        if (!is_array($result) || !isset($result[$this->streamName])) {
            return [];
        }

        $tasks = [];
        foreach ($result[$this->streamName] as $messageId => $fields) {
            $task = $this->decodeStreamTask((string)$messageId, (array)$fields);
            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    public function processDelayedJobs(): int
    {
        $delayedQueue = (string)Config::get('queue.streams.delayed', 'stream:delayed');
        $now = time();

        $tasks = Redis::zRangeByScore($delayedQueue, 0, $now);
        if (empty($tasks)) {
            return 0;
        }

        $count = 0;
        foreach ($tasks as $taskJson) {
            $task = json_decode((string)$taskJson, true);
            if (is_array($task)) {
                $task['execute_at'] = null;
                $this->pushTask($task);
            }

            Redis::zRem($delayedQueue, (string)$taskJson);
            $count++;
        }

        return $count;
    }

    public function failed(array $task, string $error): void
    {
        $task['attempts'] = (int)($task['attempts'] ?? 0) + 1;
        $task['last_error'] = $error;
        $task['failed_at'] = time();

        $this->ack($task);

        if ($task['attempts'] < (int)($task['max_attempts'] ?? $this->retryTimes)) {
            $this->pushDelayedTask($task, max(1, $this->retryDelay));
            echo "[RETRY] Task {$task['id']} retry {$task['attempts']}/{$task['max_attempts']}\n";
            return;
        }

        $this->pushToDlq($task, $error);

        // 应用层 DLQ 回调（如 Webhook 通知）；回调抛错只记 warning，不影响队列主流程
        if (self::$dlqListener !== null) {
            try {
                (self::$dlqListener)($task, $error, $this->queueType);
            } catch (\Throwable $e) {
                Logger::warning('[Queue] DLQ listener failed: ' . $e->getMessage());
            }
        }

        echo "[FAILED] Task {$task['id']} moved to failed queue\n";
    }

    public function ack(array $task): bool
    {
        $messageId = (string)($task['_message_id'] ?? '');
        if ($messageId === '') {
            return false;
        }

        return Redis::xAck($this->streamName, $this->streamGroup, [$messageId]) > 0;
    }

    public function claimStalePending(): array
    {
        $claimed = [];

        $redis = Redis::instance();
        if ($redis !== null && method_exists($redis, 'xAutoClaim')) {
            $result = Redis::xAutoClaim(
                $this->streamName,
                $this->streamGroup,
                $this->consumerName,
                $this->claimIdleMs,
                '0-0',
                $this->claimCount
            );

            if (is_array($result) && isset($result[1]) && is_array($result[1])) {
                foreach ($result[1] as $messageId => $fields) {
                    $task = $this->decodeStreamTask((string)$messageId, (array)$fields);
                    if ($task !== null) {
                        $claimed[] = $task;
                    }
                }
            }

            return $claimed;
        }

        $pending = Redis::xPending($this->streamName, $this->streamGroup, '-', '+', $this->claimCount);
        if (!is_array($pending) || empty($pending)) {
            return [];
        }

        $ids = [];
        foreach ($pending as $item) {
            if (!is_array($item) || empty($item[0])) {
                continue;
            }

            $idle = (int)($item[2] ?? 0);
            if ($idle >= $this->claimIdleMs) {
                $ids[] = (string)$item[0];
            }
        }

        if (empty($ids)) {
            return [];
        }

        $messages = Redis::xClaim($this->streamName, $this->streamGroup, $this->consumerName, $this->claimIdleMs, $ids);
        if (!is_array($messages)) {
            return [];
        }

        foreach ($messages as $messageId => $fields) {
            $task = $this->decodeStreamTask((string)$messageId, (array)$fields);
            if ($task !== null) {
                $claimed[] = $task;
            }
        }

        return $claimed;
    }

    private function pushToDlq(array $task, string $error): void
    {
        $task['last_error'] = $error;
        $task['failed_at'] = time();

        $fields = [
            'payload' => json_encode($task, JSON_UNESCAPED_UNICODE),
            'job' => (string)($task['job'] ?? ''),
            'error' => $error,
            'queue' => $this->queueType,
        ];

        if ($this->streamMaxLen > 0) {
            Redis::xAdd($this->dlqStream, '*', $fields, $this->streamMaxLen, true);
        } else {
            Redis::xAdd($this->dlqStream, '*', $fields);
        }
    }

    private function decodeStreamTask(string $messageId, array $fields): ?array
    {
        $payload = $fields['payload'] ?? null;
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $task = json_decode($payload, true);
        if (!is_array($task)) {
            return null;
        }

        $task['_message_id'] = $messageId;
        $task['_stream'] = $this->streamName;

        return $task;
    }

    private function ensureGroup(): void
    {
        $redis = Redis::instance();
        if ($redis === null) {
            throw new Exception('Redis connection unavailable while creating stream group');
        }

        try {
            $created = $redis->xGroup('CREATE', $this->streamName, $this->streamGroup, '$', true);
            if ($created === false) {
                $lastError = (string)$redis->getLastError();
                if (stripos($lastError, 'BUSYGROUP') !== false) {
                    $redis->clearLastError();
                    return;
                }
            }
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'BUSYGROUP') === false) {
                throw $e;
            }

            $redis->clearLastError();
        }
    }

    /**
     * 清理本 stream group 下的"僵尸 consumer"
     *
     * 背景：每次 worker 重启 PID 变化 → 注册新 consumer 名，但旧的从来不删除。
     *      累积到几百几千个时，Redis 内部的 PEL 元数据膨胀，新 worker 也会
     *      "拿了任务但 ACK 不动"——因为 Redis 把任务分派给死 consumer 后无法回收。
     *
     * 策略：
     *  1. 先用 XAUTOCLAIM 把 idle 超过 60s 的 pending 任务转移给本 consumer（顺手处理）
     *  2. 再用 XINFO CONSUMERS 列出所有 consumer
     *  3. 对 pending=0 且 idle > 300s 的 consumer，调 XGROUP DELCONSUMER 删除
     *
     * 由 Consumer::__construct 在 worker 启动时调用一次（不是每次 pop）。
     */
    public function cleanupStaleConsumers(int $idleThresholdSec = 300): array
    {
        $stats = ['inspected' => 0, 'removed' => 0, 'kept' => 0, 'reclaimed_pending' => 0];

        $redis = Redis::instance();
        if ($redis === null) return $stats;

        // 1. 把 idle > 60s 还有 pending 的任务先转移到本 consumer，避免误删
        try {
            $autoClaim = $redis->xAutoClaim(
                $this->streamName,
                $this->streamGroup,
                $this->consumerName,
                60_000,       // 60 秒空闲
                '0-0',
                100,
            );
            if (is_array($autoClaim) && isset($autoClaim[1]) && is_array($autoClaim[1])) {
                $stats['reclaimed_pending'] = count($autoClaim[1]);
            }
        } catch (\Throwable) {
            // 老版本 redis 没 xAutoClaim，跳过
        }

        // 2. 列所有 consumer
        try {
            $consumers = $redis->xInfo('CONSUMERS', $this->streamName, $this->streamGroup);
        } catch (\Throwable) {
            return $stats;
        }
        if (!is_array($consumers)) return $stats;

        $idleThresholdMs = $idleThresholdSec * 1000;

        foreach ($consumers as $info) {
            if (!is_array($info)) continue;
            $stats['inspected']++;

            $name    = (string)($info['name']    ?? '');
            $pending = (int)   ($info['pending'] ?? 0);
            $idle    = (int)   ($info['idle']    ?? 0);

            // 不删自己 + 不删有 pending 的 + 不删活跃的
            if ($name === '' || $name === $this->consumerName) {
                $stats['kept']++;
                continue;
            }
            if ($pending > 0 || $idle < $idleThresholdMs) {
                $stats['kept']++;
                continue;
            }

            try {
                $redis->xGroup('DELCONSUMER', $this->streamName, $this->streamGroup, $name);
                $stats['removed']++;
            } catch (\Throwable) {
                // 静默：consumer 可能在判定后被别人清掉了
            }
        }

        return $stats;
    }

    public function size(): int
    {
        return (int)Redis::xLen($this->streamName);
    }

    public function failedSize(): int
    {
        return (int)Redis::xLen($this->dlqStream);
    }

    public function delayedSize(): int
    {
        $delayedQueue = (string)Config::get('queue.streams.delayed', 'stream:delayed');
        return (int)Redis::zCard($delayedQueue);
    }

    public function clear(): bool
    {
        return Redis::del($this->streamName) !== false;
    }

    public function clearFailed(): bool
    {
        return Redis::del($this->dlqStream) !== false;
    }

    public function replayDlq(int $count = 10, ?string $targetQueue = null): array
    {
        $count = max(1, $count);

        $redis = Redis::instance();
        if ($redis === null) {
            throw new Exception('Redis connection unavailable while replaying DLQ');
        }

        $entries = $redis->xRange($this->dlqStream, '-', '+', $count);
        if (!is_array($entries) || empty($entries)) {
            return [
                'mode' => 'streams',
                'requested' => $count,
                'replayed' => 0,
                'failed' => 0,
            ];
        }

        $replayed = 0;
        $failed = 0;

        foreach ($entries as $messageId => $fields) {
            $payload = $fields['payload'] ?? null;
            $task = is_string($payload) ? json_decode($payload, true) : null;
            if (!is_array($task)) {
                $failed++;
                continue;
            }

            $job = (string)($task['job'] ?? ($fields['job'] ?? ''));
            if ($job === '') {
                $failed++;
                continue;
            }

            $queueName = strtolower((string)($targetQueue ?: ($fields['queue'] ?? 'default')));
            if (!in_array($queueName, ['default', 'high', 'low'], true)) {
                $queueName = 'default';
            }

            $data = $task['data'] ?? [];
            if (!is_array($data)) {
                $data = [];
            }

            $priority = (int)($task['priority'] ?? 0);

            $queue = new self($queueName);
            $ok = $queue->push($job, $data, $priority);

            if ($ok) {
                $redis->xDel($this->dlqStream, [(string)$messageId]);
                $replayed++;
            } else {
                $failed++;
            }
        }

        return [
            'mode' => 'streams',
            'requested' => $count,
            'replayed' => $replayed,
            'failed' => $failed,
            'target_queue' => $targetQueue,
        ];
    }

    public function listDlq(int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min(500, $limit));

        $redis = Redis::instance();
        if ($redis === null) {
            throw new Exception('Redis connection unavailable while listing DLQ');
        }

        $entries = $redis->xRevRange($this->dlqStream, '+', '-', $limit);
        if (!is_array($entries) || empty($entries)) {
            return [];
        }

        $result = [];
        foreach ($entries as $messageId => $fields) {
            $item = $this->normalizeDlqEntry((string)$messageId, (array)$fields);
            if ($item === null) {
                continue;
            }

            if (!empty($filters['keyword'])) {
                $kw = (string)$filters['keyword'];
                $haystack = ($item['job'] ?? '') . ' ' . ($item['error'] ?? '') . ' ' . json_encode($item['payload'] ?? [], JSON_UNESCAPED_UNICODE);
                if (stripos($haystack, $kw) === false) {
                    continue;
                }
            }

            if (!empty($filters['queue']) && ($item['queue'] ?? '') !== (string)$filters['queue']) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }

    public function getDlqMessage(string $messageId): ?array
    {
        if ($messageId === '') {
            return null;
        }

        $redis = Redis::instance();
        if ($redis === null) {
            throw new Exception('Redis connection unavailable while reading DLQ message');
        }

        $entries = $redis->xRange($this->dlqStream, $messageId, $messageId, 1);
        if (!is_array($entries) || empty($entries) || !isset($entries[$messageId])) {
            return null;
        }

        return $this->normalizeDlqEntry($messageId, (array)$entries[$messageId]);
    }

    public function replayDlqMessage(string $messageId, ?string $targetQueue = null): bool
    {
        if ($messageId === '') {
            return false;
        }

        $entry = $this->getDlqMessage($messageId);
        if ($entry === null) {
            return false;
        }

        $queueName = strtolower((string)($targetQueue ?: ($entry['queue'] ?? 'default')));
        if (!in_array($queueName, ['default', 'high', 'low'], true)) {
            $queueName = 'default';
        }

        $payload = $entry['payload'] ?? [];
        $job = (string)($payload['job'] ?? $entry['job'] ?? '');
        if ($job === '') {
            return false;
        }

        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $priority = (int)($payload['priority'] ?? 0);

        $queue = new self($queueName);
        if (!$queue->push($job, $data, $priority)) {
            return false;
        }

        return $this->deleteDlqMessage($messageId);
    }

    public function deleteDlqMessage(string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        $redis = Redis::instance();
        if ($redis === null) {
            throw new Exception('Redis connection unavailable while deleting DLQ message');
        }

        return (int)$redis->xDel($this->dlqStream, [$messageId]) > 0;
    }

    private function normalizeDlqEntry(string $messageId, array $fields): ?array
    {
        $payload = $fields['payload'] ?? null;
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $task = json_decode($payload, true);
        if (!is_array($task)) {
            return null;
        }

        $failedAt = (int)($task['failed_at'] ?? 0);

        return [
            'id' => $messageId,
            'job' => (string)($fields['job'] ?? ($task['job'] ?? '')),
            'queue' => (string)($fields['queue'] ?? ($task['queue'] ?? 'default')),
            'error' => (string)($fields['error'] ?? ($task['last_error'] ?? '')),
            'failed_at' => $failedAt > 0 ? date('Y-m-d H:i:s', $failedAt) : '',
            'payload' => $task,
        ];
    }

    public function stats(): array
    {
        $stats = [
            'mode' => 'streams',
            'queue' => $this->queueType,
            'stream' => $this->streamName,
            'group' => $this->streamGroup,
            'size' => $this->size(),
            'delayed' => $this->delayedSize(),
            'failed' => $this->failedSize(),
            'timestamp' => time(),
        ];

        try {
            $pending = Redis::xPending($this->streamName, $this->streamGroup);
            $stats['pending'] = (int)($pending[0] ?? 0);
        } catch (\Throwable) {
            $stats['pending'] = 0;
        }

        return $stats;
    }

    private function generateTaskId(): string
    {
        return uniqid('task_', true) . '_' . mt_rand(1000, 9999);
    }
}
