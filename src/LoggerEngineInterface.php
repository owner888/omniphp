<?php

namespace OmniPHP;

/**
 * Logger Engine Interface
 * 
 * 日志引擎接口，用于扩展不同的日志输出方式
 */
interface LoggerEngineInterface
{
    /**
     * 发送日志
     */
    public function send(string $level, string $message, array $context = []): bool;

    /**
     * 引擎名称
     */
    public function getName(): string;

    /**
     * 引擎是否可用
     */
    public function isAvailable(): bool;
}
