<?php
/**
 * 响应格式化中间件
 * 
 * 自动包装响应为统一格式: {success, data, error, meta}
 */

namespace OmniPHP\Http\Middlewares;

use OmniPHP\Http\Middleware;
use OmniPHP\Http\Context;
use Workerman\Protocols\Http\Response;

class ResponseMiddleware implements Middleware
{
    private bool $enabled;
    private array $excludePaths;
    
    public function __construct(bool $enabled = true, array $excludePaths = [])
    {
        $this->enabled = $enabled;
        $this->excludePaths = $excludePaths;
    }
    
    public function handle(Context $ctx, callable $next): mixed
    {
        // 如果中间件已禁用，直接继续
        if (!$this->enabled) {
            return $next($ctx);
        }
        
        // 如果是排除路径，直接继续
        $path = $ctx->path();
        foreach ($this->excludePaths as $excludePath) {
            if (str_starts_with($path, $excludePath)) {
                return $next($ctx);
            }
        }
        
        // 执行请求
        $result = $next($ctx);
        
        // 如果已发送响应（返回 null），不处理
        if ($result === null) {
            return null;
        }
        
        // 如果是 Response 对象，不处理
        if ($result instanceof Response) {
            return $result;
        }
        
        // 包装响应
        $wrapped = $this->wrapResponse($result, $ctx);
        
        // 获取 CORS 配置（如果 CorsMiddleware 提供）
        $corsConfig = $ctx->get('cors_config');
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
        ];
        
        // 添加 CORS headers
        if ($corsConfig) {
            $headers['Access-Control-Allow-Origin'] = $corsConfig['allow_origin'];
            if ($corsConfig['allow_credentials']) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }
        
        // 通过 Context::json() 统一发送（stripNulls 等逻辑集中在一处）
        $ctx->json($wrapped['body'], $wrapped['status'], $headers);
        
        return null;
    }
    
    /**
     * 包装响应为统一格式
     */
    private function wrapResponse(mixed $result, Context $ctx): array
    {
        // handler 可以用 $ctx->status($code) 显式声明本次响应的状态码
        // （登录 / 注册这类要返回 201 / 401 的 handler 都依赖这个写法）
        $pendingStatus = $ctx->getPendingStatus();

        // 默认成功响应
        $status = $pendingStatus ?? 200;
        $body = [
            'success' => true,
            'data' => null,
            'error' => null,
            'meta' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '1.0',
            ],
        ];

        // 如果是数组
        if (is_array($result)) {
            // 检查是否是错误响应
            if (isset($result['error'])) {
                $status = $this->getErrorStatus($result, $pendingStatus);
                $body['success'] = false;
                $body['error'] = [
                    'code' => $result['error_code'] ?? $this->getErrorCode($status),
                    'message' => $result['error'] ?? $result['message'] ?? 'Unknown error',
                    'details' => $result['details'] ?? null,
                ];
                $body['data'] = null;
            }
            // 检查是否已经是标准格式
            elseif (isset($result['success']) || isset($result['data'])) {
                $body = array_merge($body, $result);
            }
            // 普通数据响应
            else {
                $body['data'] = $result;
            }
        }
        // 其他类型直接作为 data
        else {
            $body['data'] = $result;
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * 根据错误信息判断 HTTP 状态码
     */
    private function getErrorStatus(array $result, ?int $pendingStatus = null): int
    {
        // 如果明确指定了状态码
        if (isset($result['status_code'])) {
            return (int) $result['status_code'];
        }

        // handler 通过 $ctx->status($code) 显式声明过状态码
        if ($pendingStatus !== null) {
            return $pendingStatus;
        }

        // 根据错误类型推断
        $error = $result['error'] ?? '';
        
        if (stripos($error, 'not found') !== false) {
            return 404;
        }
        if (stripos($error, 'unauthorized') !== false) {
            return 401;
        }
        if (stripos($error, 'forbidden') !== false) {
            return 403;
        }
        if (stripos($error, 'invalid') !== false) {
            return 400;
        }
        if (stripos($error, 'too many') !== false) {
            return 429;
        }
        
        return 500;
    }
    
    /** 无 error_code 时按 HTTP 状态映射到框架通用段（10xxx）——error.code 永远是整数 */
    private function getErrorCode(int $status): int
    {
        return \OmniPHP\Http\ApiCode::fromHttpStatus($status);
    }
}
