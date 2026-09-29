<?php
/**
 * HTTP 上下文（类似 Gin 的 Context）
 * 
 * 封装请求和响应，提供便捷的 API
 */

namespace OmniPHP\Http;

use OmniPHP\View\ViewEngine;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class Context
{
    private TcpConnection $connection;
    private Request $request;
    private array $params;
    private array $data = [];
    private bool $aborted = false;
    private ?ViewEngine $viewEngine = null;
    private ?int $pendingStatus = null;
    
    public function __construct(TcpConnection $connection, Request $request, array $params = [])
    {
        $this->connection = $connection;
        $this->request = $request;
        $this->params = $params;
    }
    
    // ===================================
    // 请求参数访问
    // ===================================
    
    /**
     * 获取路径参数
     * 
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }
    
    /**
     * 获取所有路径参数
     */
    public function params(): array
    {
        return $this->params;
    }
    
    /**
     * 获取查询参数（GET）
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->get();
        }
        return $this->request->get($key, $default);
    }
    
    /**
     * 获取 POST 数据
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->post();
        }
        return $this->request->post($key, $default);
    }
    
    /**
     * 获取请求参数（自动识别 GET/POST/JSON）
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // 1. 先检查 POST
        $value = $this->request->post($key);
        if ($value !== null) {
            return $value;
        }
        
        // 2. 再检查 GET
        $value = $this->request->get($key);
        if ($value !== null) {
            return $value;
        }
        
        // 3. 最后检查 JSON body
        $jsonBody = $this->jsonBody();
        if (is_array($jsonBody) && isset($jsonBody[$key])) {
            return $jsonBody[$key];
        }
        
        return $default;
    }
    
    /**
     * 获取所有请求参数（GET + POST + JSON）
     */
    public function all(): array
    {
        $data = [];
        
        // 合并 GET 参数
        $get = $this->request->get();
        if (is_array($get)) {
            $data = array_merge($data, $get);
        }
        
        // 合并 POST 参数
        $post = $this->request->post();
        if (is_array($post)) {
            $data = array_merge($data, $post);
        }
        
        // 合并 JSON 数据
        $json = $this->jsonBody();
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
        
        return $data;
    }
    
    /**
     * 获取 JSON 请求体
     */
    public function jsonBody(): mixed
    {
        $body = $this->request->rawBody();
        return $body ? json_decode($body, true) : null;
    }
    
    /**
     * 获取请求头
     */
    public function header(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->header();
        }
        return $this->request->header($key, $default);
    }
    
    /**
     * 获取请求方法
     */
    public function method(): string
    {
        return $this->request->method();
    }
    
    /**
     * 获取请求路径
     */
    public function path(): string
    {
        return $this->request->path();
    }
    
    /**
     * 获取完整 URI（路径+查询字符串）
     */
    public function uri(): string
    {
        return $this->request->uri();
    }
    
    /**
     * 获取 Cookie 值
     */
    public function cookie(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->request->cookie();
        }
        return $this->request->cookie($key, $default);
    }
    
    /**
     * 获取客户端 IP
     */
    public function ip(): string
    {
        return $this->request->header('x-real-ip')
            ?: $this->connection->getRemoteIp();
    }
    
    /**
     * 获取客户端国家码（从 nginx X-Real-Country-Short 头获取）
     */
    public function countryCode(): string
    {
        return $this->request->header('x-real-country-short') ?? '';
    }
    
    // ===================================
    // 视图渲染
    // ===================================
    
    /**
     * 渲染视图并返回HTML
     */
    public function view(string $template, array $data = []): string
    {
        if ($this->viewEngine === null) {
            $this->viewEngine = new ViewEngine();
        }
        
        return $this->viewEngine->assign($data)->fetch($template);
    }
    
    /**
     * 渲染视图并发送HTML响应
     */
    public function render(string $template, array $data = [], int $status = 200): void
    {
        $html = $this->view($template, $data);
        $this->html($html, $status);
    }
    
    // ===================================
    // 响应方法
    // ===================================
    
    /**
     * 发送 JSON 响应
     * 
     * 注意：会递归移除值为 null 的字段，避免 Android org.json 的 optString()
     * 将 JSON null 转为字符串 "null" 的已知 bug（JSONObject.NULL.toString() → "null"）。
     */
    public function json(array $data, int $status = 200, array $headers = []): void
    {
        $this->send(
            $status,
            array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
            ], $headers),
            json_encode(self::stripNulls($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * 递归移除数组中值为 null 的字段
     * 
     * 解决 Android org.json.JSONObject.optString() 的设计缺陷：
     * 当 JSON 值为 null 时，optString() 返回字符串 "null" 而非 fallback 值。
     * 移除 null 字段后，optString("key", "") 会走 fallback 路径返回 ""。
     * iOS Swift Codable 对缺失字段解码为 nil，行为不受影响。
     */
    public static function stripNulls(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                // 保留空数组 []，只递归处理有内容的数组
                $result[$key] = empty($value) ? $value : self::stripNulls($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    /**
     * 发送文本响应
     */
    public function text(string $text, int $status = 200, array $headers = []): void
    {
        $this->send(
            $status,
            array_merge([
                'Content-Type' => 'text/plain; charset=utf-8',
            ], $headers),
            $text
        );
    }
    
    /**
     * 发送 HTML 响应
     */
    public function html(string $html, int $status = 200, array $headers = []): void
    {
        $this->send(
            $status,
            array_merge([
                'Content-Type' => 'text/html; charset=utf-8',
            ], $headers),
            $html
        );
    }
    
    /**
     * 设置本次响应的 HTTP 状态码（链式调用）
     *
     * 用于 handler 直接 `return [...]` 数组、交给 ResponseMiddleware 统一包装
     * 的场景——handler 里 `$ctx->status(404); return ['error' => '...'];`，
     * ResponseMiddleware::wrapResponse 会读取 [getPendingStatus] 作为响应状态码。
     *
     * 如果 handler 自己调用 json()/error()/send() 等直接发送响应，
     * 这里设置的状态码不会生效（直接发送已经带了自己的 $status 参数）。
     */
    public function status(int $code): static
    {
        $this->pendingStatus = $code;
        return $this;
    }

    /**
     * 获取通过 [status] 设置的待用状态码；未设置过则返回 null
     */
    public function getPendingStatus(): ?int
    {
        return $this->pendingStatus;
    }

    /**
     * 发送原始响应
     */
    public function send(int $status, array $headers, string $body): void
    {
        $this->connection->send(new Response($status, $headers, $body));
        $this->aborted = true;
    }
    
    /**
     * 重定向
     */
    public function redirect(string $url, int $status = 302): void
    {
        $this->send($status, ['Location' => $url], '');
    }
    
    /**
     * 发送文件
     */
    public function file(string $filepath): void
    {
        if (!file_exists($filepath)) {
            $this->json(['error' => 'File not found'], 404);
            return;
        }
        
        $mimeType = mime_content_type($filepath) ?: 'application/octet-stream';
        // Content-Length 由 Workerman Response::__toString 按 body 长度追加，这里不能再手设（会出现两份 → nginx 502）
        $this->send(200, ['Content-Type' => $mimeType], file_get_contents($filepath));
    }
    
    // ===================================
    // 快捷响应方法
    // ===================================
    
    /**
     * 成功响应（200）
     */
    public function success(array $data = [], string $message = 'Success'): void
    {
        $this->json([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => $message,
            ],
        ]);
    }
    
    /**
     * 错误响应
     */
    public function error(string $message, int $status = 400, mixed $details = null, ?int $code = null): void
    {
        // error.code 统一整数；未传业务码时按 HTTP 状态映射到框架通用段（10xxx）
        $errorCode = $code ?? ApiCode::fromHttpStatus($status);

        $this->json([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => [
                'timestamp' => date('Y-m-d H:i:s'),
            ],
        ], $status);
    }
    
    /**
     * 未找到（404）
     */
    public function notFound(string $message = 'Not Found'): void
    {
        $this->error($message, 404);
    }
    
    /**
     * 未授权（401）
     */
    public function unauthorized(string $message = 'Unauthorized'): void
    {
        $this->error($message, 401);
    }
    
    /**
     * 禁止访问（403）
     */
    public function forbidden(string $message = 'Forbidden'): void
    {
        $this->error($message, 403);
    }
    
    /**
     * 服务器错误（500）
     */
    public function serverError(string $message = 'Internal Server Error'): void
    {
        $this->error($message, 500);
    }
    
    // ===================================
    // 中间件数据传递
    // ===================================
    
    /**
     * 设置上下文数据
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    
    /**
     * 获取上下文数据
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
    
    /**
     * 检查是否存在
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
    
    // ===================================
    // 流程控制
    // ===================================
    
    /**
     * 中断请求（停止后续中间件执行）
     */
    public function abort(int $status = 500, string $message = ''): void
    {
        if ($message) {
            $this->error($message, $status);
        }
        $this->aborted = true;
    }
    
    /**
     * 是否已中断
     */
    public function isAborted(): bool
    {
        return $this->aborted;
    }
    
    // ===================================
    // 原始对象访问（兼容性）
    // ===================================
    
    /**
     * 获取原始连接对象
     */
    public function connection(): TcpConnection
    {
        return $this->connection;
    }
    
    /**
     * 获取原始请求对象
     */
    public function request(): Request
    {
        return $this->request;
    }
}
