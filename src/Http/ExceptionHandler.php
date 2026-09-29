<?php
/**
 * 全局异常处理器
 * 
 * 捕获所有未处理的异常并转换为合适的 HTTP 响应
 */

namespace OmniPHP\Http;

use OmniPHP\Http\Exceptions\HttpException;
use Throwable;

class ExceptionHandler
{
    private bool $debug;
    
    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }
    
    /**
     * 处理异常。
     *
     * 返回 Response（不直接 send）——由 Router::dispatch 透传给 handleHttpRequest
     * 的 Response 分支统一发送，access log 才能记到真实状态码（否则异常请求会被
     * null 分支记成 200，排错误导）。
     */
    public function handle(Throwable $e, Context $ctx): \Workerman\Protocols\Http\Response
    {
        // 使用 ErrorHandler 记录错误日志（包含结构化信息）
        $operationName = $this->getOperationName($ctx);
        ErrorHandler::logError($e, $operationName, [
            'method' => $ctx->method(),
            'path' => $ctx->path(),
            'ip' => $ctx->ip(),
        ]);

        // HTTP 异常 - 直接转换
        if ($e instanceof HttpException) {
            return $this->handleHttpException($e, $ctx);
        }

        // 其他异常 - 转换为 500
        return $this->handleGenericException($e, $ctx);
    }
    
    /**
     * 获取操作名称（从路由路径推断）
     */
    private function getOperationName(Context $ctx): string
    {
        $path = trim($ctx->path(), '/');
        $parts = explode('/', $path);
        
        // 转换路径为操作名称：api/context-cache/list -> ContextCache::list
        if (count($parts) >= 2) {
            $module = str_replace('-', '', ucwords($parts[count($parts) - 2], '-'));
            $action = str_replace('-', '', ucwords($parts[count($parts) - 1], '-'));
            return "{$module}::{$action}";
        }
        
        return $path;
    }
    
    /**
     * 处理 HTTP 异常
     */
    private function handleHttpException(HttpException $e, Context $ctx): \Workerman\Protocols\Http\Response
    {
        $data = [
            'error' => $e->getMessage(),
            'status_code' => $e->getStatusCode(),
        ];

        if ($e->getDetails()) {
            $data['details'] = $e->getDetails();
        }

        // 在调试模式下添加堆栈追踪
        if ($this->debug) {
            $data['trace'] = $this->getTrace($e);
        }

        return new \Workerman\Protocols\Http\Response(
            $e->getStatusCode(),
            array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
            ], $e->getHeaders()),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * 处理通用异常
     */
    private function handleGenericException(Throwable $e, Context $ctx): \Workerman\Protocols\Http\Response
    {
        // 记录错误日志
        error_log(sprintf(
            "[%s] %s in %s:%d\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
        
        $data = [
            'error' => $this->debug ? $e->getMessage() : 'Internal Server Error',
            'status_code' => 500,
        ];
        
        // 在调试模式下添加详细信息
        if ($this->debug) {
            $data['exception'] = get_class($e);
            $data['file'] = $e->getFile();
            $data['line'] = $e->getLine();
            $data['trace'] = $this->getTrace($e);
        }
        
        return new \Workerman\Protocols\Http\Response(
            500,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
    
    /**
     * 获取格式化的堆栈追踪
     */
    private function getTrace(Throwable $e): array
    {
        $trace = [];
        foreach ($e->getTrace() as $i => $item) {
            $trace[] = sprintf(
                "#%d %s%s%s() at %s:%d",
                $i,
                $item['class'] ?? '',
                $item['type'] ?? '',
                $item['function'] ?? '',
                $item['file'] ?? 'unknown',
                $item['line'] ?? 0
            );
        }
        return $trace;
    }
}
