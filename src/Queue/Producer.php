<?php

namespace OmniPHP\Queue;

/**
 * 生产者类 - 用于发送任务到队列
 */
class Producer
{
    private RedisQueue $queue;
    private RedisQueue $highQueue;
    private RedisQueue $lowQueue;

    public function __construct()
    {
        $this->queue = new RedisQueue('default');
        $this->highQueue = new RedisQueue('high');
        $this->lowQueue = new RedisQueue('low');
    }

    /**
     * 发送普通任务
     */
    public function push(string $job, array $data = []): bool
    {
        return $this->queue->push($job, $data);
    }

    /**
     * 发送高优先级任务
     */
    public function pushHigh(string $job, array $data = []): bool
    {
        return $this->highQueue->push($job, $data);
    }

    /**
     * 发送低优先级任务
     */
    public function pushLow(string $job, array $data = []): bool
    {
        return $this->lowQueue->push($job, $data);
    }

    /**
     * 发送延迟任务
     * @param string $job 任务名称
     * @param array $data 任务数据
     * @param int $delay 延迟秒数
     */
    public function pushDelayed(string $job, array $data, int $delay): bool
    {
        return $this->queue->pushDelayed($job, $data, $delay);
    }

    /**
     * 批量发送任务
     */
    public function pushBatch(array $jobs): int
    {
        return $this->queue->pushBatch($jobs);
    }

    /**
     * 获取队列统计信息
     */
    public function stats(): array
    {
        return [
            'default' => $this->queue->stats(),
            'high' => $this->highQueue->stats(),
            'low' => $this->lowQueue->stats(),
        ];
    }
}
