<?php
/**
 * 简洁的路由系统（支持动态参数 + 类型验证）
 * 
 * 使用示例：
 * Router::get('/api/books/{id:int}', fn($conn, $req, $params) => [...]);
 * Router::get('/api/users/{username:alpha}', fn($conn, $req, $params) => [...]);
 * Router::get('/api/posts/{slug:slug}', fn($conn, $req, $params) => [...]);
 * Router::get('/api/files/{path:path}', fn($conn, $req, $params) => [...]);
 */

namespace OmniPHP\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class Router
{
    private static array $routes = [];
    private static array $groups = [];
    private static array $middlewares = [];
    private static array $groupMiddlewares = [];
    private static array $staticRoutes = [];
    private static array $staticFiles = [];
    private static ?ExceptionHandler $exceptionHandler = null;
    /** @var class-string<Context>|null 自定义 Context 类名 */
    private static ?string $contextClass = null;
    /** @var (callable(TcpConnection, Request, array): Context)|null 自定义 Context 工厂 */
    private static mixed $contextFactory = null;
    
    /**
     * 参数类型验证规则
     */
    private const PARAM_PATTERNS = [
        'int'      => '[0-9]+',              // 纯数字（正整数）
        'sint'     => '-?[0-9]+',            // 带符号整数（TG chat_id 这种负数用）
        'id'       => '[0-9]+',              // ID（数字）
        'alpha'    => '[a-zA-Z]+',           // 纯字母
        'alnum'    => '[a-zA-Z0-9]+',        // 字母+数字
        'slug'     => '[a-z0-9\-]+',         // URL slug（小写字母、数字、连字符）
        'uuid'     => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', // UUID
        'path'     => '[a-zA-Z0-9/_\-\.]+',  // 文件路径（限制字符，防止路径遍历）
        'any'      => '[^/]+',               // 任意字符（默认）
    ];
    
    /**
     * 注册 GET 路由
     */
    public static function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        self::addRoute('GET', $path, $handler, $middlewares);
    }
    
    /**
     * 注册 POST 路由
     */
    public static function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        self::addRoute('POST', $path, $handler, $middlewares);
    }
    
    /**
     * 注册 PUT 路由
     */
    public static function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        self::addRoute('PUT', $path, $handler, $middlewares);
    }
    
    /**
     * 注册 DELETE 路由
     */
    public static function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        self::addRoute('DELETE', $path, $handler, $middlewares);
    }
    
    /**
     * 注册 PATCH 路由
     */
    public static function patch(string $path, callable|array $handler, array $middlewares = []): void
    {
        self::addRoute('PATCH', $path, $handler, $middlewares);
    }
    
    /**
     * 注册任意方法路由
     */
    public static function any(string $path, callable|array $handler, array $middlewares = []): void
    {
        self::addRoute('*', $path, $handler, $middlewares);
    }

    /**
     * 设置自定义 HTTP Context 类 (必须继承自 OmniPHP\Http\Context)
     *
     * @param class-string<Context> $className
     */
    public static function setContextClass(string $className): void
    {
        if (!is_subclass_of($className, Context::class)) {
            throw new \InvalidArgumentException("Context class '{$className}' must extend " . Context::class);
        }
        self::$contextClass = $className;
    }

    /**
     * 设置自定义 HTTP Context 构造工厂闭包
     *
     * @param callable(TcpConnection, Request, array): Context $factory
     */
    public static function setContextFactory(callable $factory): void
    {
        self::$contextFactory = $factory;
    }

    /**
     * 实例化 Context 上下文
     *
     * @param array<string, mixed> $params
     */
    public static function createContext(TcpConnection $connection, Request $request, array $params = []): Context
    {
        if (self::$contextFactory !== null) {
            return (self::$contextFactory)($connection, $request, $params);
        }
        if (self::$contextClass !== null) {
            return new self::$contextClass($connection, $request, $params);
        }
        return new Context($connection, $request, $params);
    }

    /**
     * 托管静态资源目录 (对标 Go Gin 的 r.Static("/static", "./public/static"))
     *
     * @param array{index?: string, spaFallback?: bool, cacheControl?: string, etag?: bool} $options
     */
    public static function static(string $prefix, string $dir, array $options = []): void
    {
        self::$staticRoutes[] = [
            'prefix' => $prefix,
            'dir' => $dir,
            'options' => $options,
        ];
    }

    /**
     * 托管单个静态文件 (对标 Go Gin 的 r.StaticFile("/favicon.ico", "./public/favicon.ico"))
     *
     * @param array{cacheControl?: string, etag?: bool} $options
     */
    public static function staticFile(string $path, string $filepath, array $options = []): void
    {
        self::$staticFiles[$path] = [
            'filepath' => $filepath,
            'options' => $options,
        ];
    }
    
    /**
     * 添加全局中间件
     */
    public static function middleware(Middleware|array $middleware): void
    {
        $middlewares = is_array($middleware) ? $middleware : [$middleware];
        self::$middlewares = array_merge(self::$middlewares, $middlewares);
    }
    
    /**
     * 设置异常处理器
     */
    public static function setExceptionHandler(ExceptionHandler $handler): void
    {
        self::$exceptionHandler = $handler;
    }
    
    /**
     * 路由组（添加前缀和中间件）
     */
    public static function group(string $prefix, callable $callback, array $middlewares = []): void
    {
        self::$groups[] = $prefix;
        
        if (!empty($middlewares)) {
            self::$groupMiddlewares[] = $middlewares;
        }
        
        $callback();
        
        array_pop(self::$groups);
        if (!empty($middlewares)) {
            array_pop(self::$groupMiddlewares);
        }
    }
    
    /**
     * 添加路由
     */
    private static function addRoute(string $method, string $path, callable|array $handler, array $routeMiddlewares = []): void
    {
        $fullPath = implode('', self::$groups) . $path;
        
        // 解析路径参数和类型
        // {id} => 参数名: id, 类型: any (默认)
        // {id:int} => 参数名: id, 类型: int
        $pattern = $fullPath;
        $params = [];
        $types = [];
        
        // 提取所有参数及其类型
        if (preg_match_all('/{([^}:]+)(?::([^}]+))?}/', $pattern, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $paramName = $match[1];
                $paramType = $match[2] ?? 'any';
                
                $params[] = $paramName;
                $types[$paramName] = $paramType;
                
                // 获取对应的正则表达式
                $regex = self::PARAM_PATTERNS[$paramType] ?? self::PARAM_PATTERNS['any'];
                
                // 替换路径中的参数占位符
                $pattern = str_replace($match[0], "({$regex})", $pattern);
            }
        }
        
        // 转换为完整的正则表达式
        $pattern = '#^' . $pattern . '$#';
        
        // 收集中间件：组级（外层在前）→ 路由级；全局中间件在 dispatch 时再拼到最前
        $middlewares = [];
        foreach (self::$groupMiddlewares as $groupMiddleware) {
            $middlewares = array_merge($middlewares, $groupMiddleware);
        }
        $middlewares = array_merge($middlewares, $routeMiddlewares);
        
        self::$routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'pattern' => $pattern,
            'params' => $params,
            'types' => $types,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }
    
    /**
     * 匹配并执行路由
     * 
     * @return mixed 处理结果，null 表示需要流式处理（已发送响应）
     */
    public static function dispatch(TcpConnection $connection, Request $request): mixed
    {
        $method = $request->method();
        $path = preg_replace('#/+#', '/', $request->path());
        
        // 安全检查：防止路径遍历攻击
        if (self::containsPathTraversal($path)) {
            return ['error' => 'Invalid path', 'message' => 'Path traversal detected'];
        }

        // 1. 静态单文件快速匹配
        if (isset(self::$staticFiles[$path])) {
            $f = self::$staticFiles[$path];
            if (StaticServer::serveFile($connection, $request, $f['filepath'], $f['options'])) {
                return null;
            }
        }

        // 2. 静态目录匹配 (如 /static)
        foreach (self::$staticRoutes as $st) {
            if (StaticServer::serve($connection, $request, $st['prefix'], $st['dir'], $st['options'])) {
                return null;
            }
        }
        
        foreach (self::$routes as $route) {
            // 检查 HTTP 方法
            // HEAD 复用 GET 路由（RFC 9110 §9.3.2）；handler 自行按 $ctx->method() 决定是否省略 body（如 SyncHandler::blobGet）
            $routeMethod = $route['method'];
            if ($routeMethod !== '*' && $routeMethod !== $method && !($method === 'HEAD' && $routeMethod === 'GET')) {
                continue;
            }
            
            // 检查路径匹配
            if (preg_match($route['pattern'], $path, $matches)) {
                // 提取并验证路径参数
                $params = [];
                array_shift($matches); // 移除完整匹配
                
                foreach ($route['params'] as $index => $paramName) {
                    $value = $matches[$index] ?? null;
                    
                    if ($value === null) {
                        continue;
                    }
                    
                    // 对参数值进行安全清理
                    $value = self::sanitizeParam($value, $route['types'][$paramName] ?? 'any');
                    
                    $params[$paramName] = $value;
                }
                
                // 创建 Context (支持自定义子类与工厂扩展)
                $ctx = self::createContext($connection, $request, $params);
                
                // 合并全局中间件和路由中间件
                $middlewares = array_merge(self::$middlewares, $route['middlewares'] ?? []);
                
                // 构建中间件管道
                $handler = $route['handler'];
                $pipeline = self::buildPipeline($middlewares, function($ctx) use ($handler) {
                    // 支持数组格式 [Class::class, 'method']
                    if (is_array($handler)) {
                        [$class, $method] = $handler;
                        if (is_string($class)) {
                            $class = new $class();
                        }
                        return $class->$method($ctx);
                    }
                    
                    // 调用闭包或函数
                    return $handler($ctx);
                });
                
                // 执行中间件管道（捕获异常）
                try {
                    return $pipeline($ctx);
                } catch (\Throwable $e) {
                    // 使用异常处理器：返回 Response 交给 handleHttpRequest 发送，
                    // access log 按 Response 真实状态码记录（而不是 null 分支的 200）
                    if (self::$exceptionHandler) {
                        return self::$exceptionHandler->handle($e, $ctx);
                    }
                    // 如果没有异常处理器，重新抛出
                    throw $e;
                }
            }
        }
        
        // 未找到路由
        return ['error' => 'Not Found', 'path' => $path];
    }
    
    /**
     * 构建中间件管道（洋葱模型）
     */
    private static function buildPipeline(array $middlewares, callable $core): callable
    {
        // 从后往前构建管道
        $pipeline = $core;
        
        foreach (array_reverse($middlewares) as $middleware) {
            $next = $pipeline;
            $pipeline = function($ctx) use ($middleware, $next) {
                return $middleware->handle($ctx, $next);
            };
        }
        
        return $pipeline;
    }
    
    /**
     * 检测路径遍历攻击
     */
    private static function containsPathTraversal(string $path): bool
    {
        // 检查常见的路径遍历模式
        $dangerous = ['../', '..\\', '%2e%2e/', '%2e%2e\\', '....', '\0'];
        
        foreach ($dangerous as $pattern) {
            if (stripos($path, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 对参数值进行安全清理
     */
    private static function sanitizeParam(string $value, string $type): string|int
    {
        switch ($type) {
            case 'int':
            case 'id':
            case 'sint':
                // 转换为整数（sint 已经在正则阶段允许过 - 号）
                return (int) $value;
                
            case 'alpha':
                // 只保留字母
                return preg_replace('/[^a-zA-Z]/', '', $value);
                
            case 'alnum':
                // 只保留字母和数字
                return preg_replace('/[^a-zA-Z0-9]/', '', $value);
                
            case 'slug':
                // 只保留 slug 允许的字符
                return preg_replace('/[^a-z0-9\-]/', '', strtolower($value));
                
            case 'uuid':
                // UUID 已经通过正则验证，直接返回
                return strtolower($value);
                
            case 'path':
                // 路径需要额外清理
                // 移除多余的斜杠
                $value = preg_replace('#/+#', '/', $value);
                // 移除开头和结尾的斜杠
                $value = trim($value, '/');
                // 再次检查路径遍历
                if (str_contains($value, '..')) {
                    return '';
                }
                return $value;
                
            case 'any':
            default:
                // 对任意类型进行基本清理
                // HTML 实体编码（防止 XSS）
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * 清空所有路由（测试用）
     */
    public static function clear(): void
    {
        self::$routes = [];
        self::$groups = [];
        self::$middlewares = [];
        self::$groupMiddlewares = [];
        self::$staticRoutes = [];
        self::$staticFiles = [];
        self::$contextClass = null;
        self::$contextFactory = null;
        self::$exceptionHandler = null;
    }
    
    /**
     * 获取所有已注册的路由（调试用）
     */
    public static function getRoutes(): array
    {
        return array_map(fn($route) => [
            'method' => $route['method'],
            'path' => $route['path'],
            'params' => $route['params'],
            'types' => $route['types'],
        ], self::$routes);
    }
    
    /**
     * 获取支持的参数类型列表
     */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::PARAM_PATTERNS);
    }
}
