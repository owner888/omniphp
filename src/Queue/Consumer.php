<?php

namespace OmniPHP\Queue;

use Exception;
use OmniPHP\Config;

/**
 * 消费者类 - 用于处理队列中的任务
 */
class Consumer
{
    private RedisQueue $queue;
    private string $queueType;
    private array $handlers;
    private bool $running = true;
    private int $processedCount = 0;
    private int $failedCount = 0;

    public function __construct(string $queueType = 'default')
    {
        $this->queueType = $queueType;
        $this->queue = new RedisQueue($queueType);
        $this->loadHandlers();

        // 启动时清理僵尸 consumer + 把 idle pending 拉回本 consumer
        // 不清理会累积成百上千个，最终拖垮 Redis Stream 调度
        try {
            $cleanup = $this->queue->cleanupStaleConsumers();
            if ($cleanup['removed'] > 0 || $cleanup['reclaimed_pending'] > 0) {
                echo sprintf(
                    "[Consumer] cleanup on %s: removed=%d, kept=%d, reclaimed_pending=%d\n",
                    $queueType,
                    $cleanup['removed'],
                    $cleanup['kept'],
                    $cleanup['reclaimed_pending'],
                );
            }
        } catch (\Throwable $e) {
            echo "[Consumer] cleanupStaleConsumers failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 加载任务处理器
     */
    private function loadHandlers(): void
    {
        $this->handlers = Config::get('queue.handlers', []);
    }

    /**
     * 开始消费任务
     */
    public function consume(): void
    {
        echo "[Consumer] Started at " . date('Y-m-d H:i:s') . "\n";
        $stats = $this->queue->stats();
        echo "[Consumer] Mode: {$stats['mode']}\n";
        echo "[Consumer] Listening on queue: {$stats['queue']}\n";
        if (($stats['mode'] ?? '') === 'streams') {
            echo "[Consumer] Listening on stream: {$stats['stream']} (group: {$stats['group']})\n";
        }

        while ($this->running) {
            try {
                $this->consumeClaimedTasks();

                // 阻塞式获取任务（等待3秒）
                $task = $this->queue->pop(3);

                if ($task === null) {
                    // 空闲时检查内存
                    $this->checkMemory();
                    continue;
                }

                $this->processTask($task);

            } catch (Exception $e) {
                echo "[ERROR] Consumer error: " . $e->getMessage() . "\n";
                sleep(1);
            }
        }

        echo "[Consumer] Stopped at " . date('Y-m-d H:i:s') . "\n";
        echo "[Consumer] Processed: {$this->processedCount}, Failed: {$this->failedCount}\n";
    }

    /**
     * 处理单个任务
     *
     * 防 hang 策略：
     *  - set_time_limit(N) 给 PHP 自带的 max_execution_time 上限（N=120s 默认够 AI 调用）
     *  - 不管成功失败都必须 ack，否则消息永远 pending 拖死 stream group
     *  - catch Throwable 而不只是 Exception（捕获 Error / 致命错误）
     */
    private function processTask(array $task): void
    {
        $startTime = microtime(true);
        $job = $task['job'] ?? 'unknown';
        $taskId = $task['id'] ?? 'unknown';
        $maxSec = (int)Config::get('queue.consumer.task_timeout_sec', 120);

        echo "[PROCESS] Task {$taskId} - Job: {$job}\n";

        // PHP 级 hard timeout（防 cURL / AI 长 hang）
        set_time_limit($maxSec);

        try {
            $handler = $this->getHandler($job);
            if ($handler === null) {
                throw new Exception("Handler not found for job: {$job}");
            }

            $result = $handler->handle($task['data'] ?? []);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            echo "[SUCCESS] Task {$taskId} completed in {$duration}ms\n";

            $this->queue->ack($task);
            $this->processedCount++;
        } catch (\Throwable $e) {
            // catch Throwable 抓住 Error/TypeError 也确保 ack
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            echo "[ERROR] Task {$taskId} failed in {$duration}ms: " . $e->getMessage() . "\n";

            // failed() 内部已经 ack 了，所以这里不需要再调
            try {
                $this->queue->failed($task, $e->getMessage());
            } catch (\Throwable $e2) {
                // 兜底：failed() 自己崩了，至少强行 ack 不让消息再次重试
                $this->queue->ack($task);
                echo "[ERROR] failed() also threw: " . $e2->getMessage() . " — force-acked\n";
            }
            $this->failedCount++;
        } finally {
            // 重置 time_limit，等下一轮 pop 重新设
            set_time_limit(0);
        }
    }

    private function consumeClaimedTasks(): void
    {
        $claimed = $this->queue->claimStalePending();
        if (empty($claimed)) {
            return;
        }

        echo "[Consumer] Claimed " . count($claimed) . " stale pending task(s) on {$this->queueType}\n";
        foreach ($claimed as $task) {
            if (!$this->running) {
                break;
            }
            $this->processTask($task);
        }
    }

    /**
     * 获取任务处理器
     */
    private function getHandler(string $job): ?HandlerInterface
    {
        $handlerClass = $this->handlers[$job] ?? null;

        if ($handlerClass === null || !class_exists($handlerClass)) {
            return null;
        }

        return new $handlerClass();
    }

    /**
     * 检查内存使用
     */
    private function checkMemory(): void
    {
        $memory = memory_get_usage(true);
        $maxMemory = Config::get('queue.consumer.max_memory', 128 * 1024 * 1024);

        if ($memory > $maxMemory) {
            echo "[WARNING] Memory usage: " . round($memory / 1024 / 1024, 2) . "MB, restarting...\n";
            $this->stop();
        }
    }

    /**
     * 停止消费者
     */
    public function stop(): void
    {
        $this->running = false;
        echo "[Consumer] Stopping gracefully...\n";
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'processed' => $this->processedCount,
            'failed' => $this->failedCount,
            'queue_size' => $this->queue->size(),
            'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
        ];
    }
}

/**
 * 任务处理器接口
 */
interface HandlerInterface
{
    /**
     * 处理任务
     * @param array $data 任务数据
     * @return mixed
     */
    public function handle(array $data);
}
