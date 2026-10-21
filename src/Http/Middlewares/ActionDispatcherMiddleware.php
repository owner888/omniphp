<?php

declare(strict_types=1);

namespace OmniPHP\Http\Middlewares;

use OmniPHP\Http\Context;
use OmniPHP\Http\Middleware;

/**
 * 存量系统与游戏协议动作分发中间件 (RPC / Action Dispatcher)
 *
 * 智能将 `?c={controller}&m={method}` (或自定义参数名) 请求动态路由至对应的无状态静态 Handler 动作。
 * 若请求未包含指定参数，则透明放行至下游常规 RESTful 路由管道。
 */
final class ActionDispatcherMiddleware implements Middleware
{
    /**
     * @param array<string, class-string> $handlers 控制器映射表
     * @param string $controllerParam 控制器参数名 (默认 'c')
     * @param string $methodParam 方法参数名 (默认 'm')
     */
    public function __construct(
        private readonly array $handlers = [],
        private readonly string $controllerParam = 'c',
        private readonly string $methodParam = 'm'
    ) {
    }

    public function handle(Context $ctx, callable $next): mixed
    {
        $c = (string)$ctx->input($this->controllerParam, '');
        $m = (string)$ctx->input($this->methodParam, '');

        if ($c === '' || $m === '') {
            return $next($ctx);
        }

        $c = strtolower(trim($c));
        $m = strtolower(trim($m));

        if (!isset($this->handlers[$c])) {
            return [
                'status' => -404,
                'message' => "未找到指定的模块处理器 [{$c}]",
            ];
        }

        $class = $this->handlers[$c];
        if (!method_exists($class, $m)) {
            return [
                'status' => -404,
                'message' => "模块 [{$c}] 中不存在执行动作 [{$m}]",
            ];
        }

        return $class::$m($ctx);
    }
}
