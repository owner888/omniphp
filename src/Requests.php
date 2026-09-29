<?php

namespace OmniPHP;
/**
 * Requests - 简洁的 HTTP 客户端
 * 
 * 类似 Python requests 库的 PHP 实现
 * 
 * @example
 * $response = Requests::get('https://api.example.com/data');
 * $response = Requests::post('https://api.example.com/submit', ['json' => ['key' => 'value']]);
 * $response = Requests::put('https://api.example.com/update', ['data' => ['key' => 'value']]);
 * $response = Requests::delete('https://api.example.com/delete');
 */
class Requests
{
    // 默认配置
    private static array $defaultConfig = [
        'timeout' => 30,
        'connect_timeout' => 10,
        'user_agent' => 'PHP-Requests/1.0',
        'verify_ssl' => false,
        'follow_redirects' => true,
        'max_redirects' => 5,
        'headers' => [],
        'cookies' => null,
    ];

    private array $config;
    private array $history = [];

    /**
     * 构造函数
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::$defaultConfig, $config);
    }

    /**
     * 创建 Requests 实例的快捷方法
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * 设置全局默认配置
     */
    public static function setDefaultConfig(array $config): void
    {
        self::$defaultConfig = array_merge(self::$defaultConfig, $config);
    }

    // ==================== HTTP Methods ====================

    /**
     * GET 请求
     */
    public static function get(string $url, array $options = []): Response
    {
        return self::create()->_request('GET', $url, $options);
    }

    /**
     * POST 请求
     */
    public static function post(string $url, array $options = []): Response
    {
        return self::create()->_request('POST', $url, $options);
    }

    /**
     * PUT 请求
     */
    public static function put(string $url, array $options = []): Response
    {
        return self::create()->_request('PUT', $url, $options);
    }

    /**
     * PATCH 请求
     */
    public static function patch(string $url, array $options = []): Response
    {
        return self::create()->_request('PATCH', $url, $options);
    }

    /**
     * DELETE 请求
     */
    public static function delete(string $url, array $options = []): Response
    {
        return self::create()->_request('DELETE', $url, $options);
    }

    /**
     * HEAD 请求
     */
    public static function head(string $url, array $options = []): Response
    {
        return self::create()->_request('HEAD', $url, $options);
    }

    /**
     * OPTIONS 请求
     */
    public static function options(string $url, array $options = []): Response
    {
        return self::create()->_request('OPTIONS', $url, $options);
    }

    // ==================== Instance Methods ====================

    /**
     * 发送请求
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        return $this->_request($method, $url, $options);
    }

    /**
     * 获取请求历史
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * 清空请求历史
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    // ==================== Private Methods ====================

    /**
     * 内部请求处理
     */
    private function _request(string $method, string $url, array $options): Response
    {
        $options = $this->parseOptions($options);
        
        $ch = curl_init();
        
        $this->setupCurlOptions($ch, $method, $url, $options);
        
        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // curl_error() 成功时返回空字符串 ""，转为 null 以便 hasError() 正确判断
        $response = new Response($httpCode, $responseBody ?: '', $options['headers'], $error !== '' ? $error : null);
        
        $this->history[] = [
            'method' => $method,
            'url' => $url,
            'status_code' => $httpCode,
            'response' => $response,
        ];
        
        return $response;
    }

    /**
     * 解析选项
     */
    private function parseOptions(array $options): array
    {
        return [
            'params' => $options['params'] ?? [],
            'data' => $options['data'] ?? null,
            'json' => $options['json'] ?? null,
            'files' => $options['files'] ?? [],
            'headers' => array_merge($this->config['headers'], $options['headers'] ?? []),
            'cookies' => $options['cookies'] ?? $this->config['cookies'],
            'timeout' => $options['timeout'] ?? $this->config['timeout'],
            'connect_timeout' => $options['connect_timeout'] ?? $this->config['connect_timeout'],
            'user_agent' => $options['user_agent'] ?? $this->config['user_agent'],
            'verify_ssl' => $options['verify_ssl'] ?? $this->config['verify_ssl'],
            'follow_redirects' => $options['follow_redirects'] ?? $this->config['follow_redirects'],
            'max_redirects' => $options['max_redirects'] ?? $this->config['max_redirects'],
            'auth' => $options['auth'] ?? null,
        ];
    }

    /**
     * 设置 CURL 选项
     */
    private function setupCurlOptions($ch, string $method, string $url, array $options): void
    {
        // URL 处理（添加查询参数）
        if (!empty($options['params'])) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($options['params']);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'],
            CURLOPT_USERAGENT => $options['user_agent'],
            CURLOPT_SSL_VERIFYPEER => $options['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $options['verify_ssl'] ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $options['follow_redirects'],
            CURLOPT_MAXREDIRS => $options['max_redirects'],
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_NOBODY => strtoupper($method) === 'HEAD',
        ]);

        // 请求头
        $headers = [];
        foreach ($options['headers'] as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // 认证
        if (!empty($options['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $options['auth']);
        }

        // 请求体处理
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $body = null;

            // JSON 数据
            if (!empty($options['json'])) {
                $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE);
                if (!isset($options['headers']['Content-Type'])) {
                    $headers[] = 'Content-Type: application/json';
                }
            }
            // 表单数据 / 原始 body
            elseif (!empty($options['data'])) {
                $body = is_array($options['data']) 
                    ? http_build_query($options['data']) 
                    : $options['data'];
                // 仅在用户未指定 Content-Type 时添加默认值
                if (!isset($options['headers']['Content-Type'])) {
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                }
            }
            // 文件上传
            elseif (!empty($options['files'])) {
                // 处理文件上传
                if (class_exists('\CURLFile')) {
                    $postFields = [];
                    foreach ($options['files'] as $key => $file) {
                        if (is_string($file)) {
                            $postFields[$key] = new \CURLFile($file);
                        } elseif (is_array($file)) {
                            $postFields[$key] = new \CURLFile($file['path'] ?? $file[0], $file['type'] ?? null, $file['name'] ?? basename($file['path'] ?? $file[0]));
                        }
                    }
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                    return;
                }
            }

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
        }
    }
}

/**
 * HTTP Response 类
 */
class Response
{
    public int $statusCode;
    public string $body;
    public array $headers;
    public ?string $error;
    public ?array $json = null;

    public function __construct(int $statusCode, string $body, array $headers, ?string $error = null)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
        $this->error = $error;

        // 自动解析 JSON
        if ($this->isJson()) {
            $this->json = json_decode($body, true);
        }
    }

    /**
     * 检查是否为 JSON 响应
     */
    public function isJson(): bool
    {
        $contentType = $this->getContentType();
        return strpos($contentType, 'application/json') !== false;
    }

    /**
     * 获取内容类型
     */
    public function getContentType(): string
    {
        foreach ($this->headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return str_ireplace('Content-Type:', '', $header);
            }
        }
        return '';
    }

    /**
     * 获取响应文本
     */
    public function text(): string
    {
        return $this->body;
    }

    /**
     * 获取 JSON 数据
     */
    public function json(): ?array
    {
        return $this->json;
    }

    /**
     * 检查是否成功 (2xx 状态码)
     */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * 检查是否为重定向
     */
    public function isRedirect(): bool
    {
        return in_array($this->statusCode, [301, 302, 303, 307, 308]);
    }

    /**
     * 检查是否有错误
     */
    public function hasError(): bool
    {
        return $this->error !== null || $this->statusCode >= 400;
    }

    /**
     * 获取状态码描述
     */
    public function getStatusReason(): string
    {
        $reasons = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];
        return $reasons[$this->statusCode] ?? 'Unknown';
    }

    /**
     * 转换为字符串
     */
    public function __toString(): string
    {
        return $this->body;
    }
}

/**
 * 请求异常类
 */
class RequestsException extends \Exception
{
    public ?Response $response;

    public function __construct(string $message, ?Response $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->response = $response;
    }
}
