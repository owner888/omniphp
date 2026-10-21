<?php

declare(strict_types=1);

namespace OmniPHP\Http\Middlewares;

use OmniPHP\Http\Context;
use OmniPHP\Http\Middleware;
use OmniPHP\Logger;

/**
 * 存量系统与游戏协议动作分发中间件 (RPC / Action Dispatcher)
 *
 * 智能将 `?c={controller}&m={method}` (或自定义参数名) 请求动态路由至对应的无状态静态 Handler 动作。
 * 支持 autoFallback 智能降级模式，遇到未实现接口时自动记录 Logger::warning 并下发平滑兼容信封。
 */
final class ActionDispatcherMiddleware implements Middleware
{
    /**
     * @param array<string, class-string> $handlers 控制器映射表
     * @param string $controllerParam 控制器参数名 (默认 'c')
     * @param string $methodParam 方法参数名 (默认 'm')
     * @param bool $autoFallback 是否启用未实现接口自动平滑降级并告警 (默认 false)
     */
    public function __construct(
        private readonly array $handlers = [],
        private readonly string $controllerParam = 'c',
        private readonly string $methodParam = 'm',
        private readonly bool $autoFallback = false
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

        $missing = !isset($this->handlers[$c]) || !method_exists($this->handlers[$c], $m);

        if ($missing) {
            if ($this->autoFallback) {
                Logger::warning("[ActionDispatcher] 命中未覆盖接口: {$c}.{$m}", [
                    'controller' => $c,
                    'method' => $m,
                    'params' => $ctx->all(),
                ]);

                return [
                    'status' => 1,
                    'is_fallback' => 1,
                    'controller' => $c,
                    'method' => $m,
                    'message' => "功能 [{$c}.{$m}] 正在筹备中，敬请期待！",
                    'list' => [],
                    'info' => [],
                ];
            }

            return [
                'status' => -404,
                'message' => "未找到指定的模块动作 [{$c}.{$m}]",
            ];
        }

        $class = $this->handlers[$c];
        return $class::$m($ctx);
    }
}
