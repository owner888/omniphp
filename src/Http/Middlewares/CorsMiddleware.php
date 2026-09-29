<?php
/**
 * CORS 中间件
 * 
 * 处理跨域请求，自动添加 CORS 头
 */

namespace OmniPHP\Http\Middlewares;

use OmniPHP\Http\Middleware;
use OmniPHP\Http\Context;
use Workerman\Protocols\Http\Response;

class CorsMiddleware implements Middleware
{
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allow_origin' => '*',
            'allow_methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'allow_headers' => 'Content-Type, Authorization, X-Requested-With',
            'allow_credentials' => false,
            'max_age' => 86400,
        ], $config);
    }
    
    public function handle(Context $ctx, callable $next): mixed
    {
        // 处理 OPTIONS 预检请求
        if ($ctx->method() === 'OPTIONS') {
            $ctx->connection()->send(new Response(200, [
                'Access-Control-Allow-Origin' => $this->config['allow_origin'],
                'Access-Control-Allow-Methods' => $this->config['allow_methods'],
                'Access-Control-Allow-Headers' => $this->config['allow_headers'],
                'Access-Control-Max-Age' => (string) $this->config['max_age'],
                'Access-Control-Allow-Credentials' => $this->config['allow_credentials'] ? 'true' : 'false',
            ], ''));
            return null;
        }
        
        // 保存 CORS 配置到 Context，供后续中间件使用
        $ctx->set('cors_config', $this->config);
        
        // 继续处理请求
        return $next($ctx);
    }
}
