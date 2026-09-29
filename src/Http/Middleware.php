<?php
/**
 * 中间件接口
 * 
 * 中间件可以在请求到达路由处理器之前或之后执行
 */

namespace OmniPHP\Http;

interface Middleware
{
    /**
     * 处理请求
     * 
     * @param Context $ctx 请求上下文
     * @param callable $next 调用下一个中间件或路由处理器
     * @return mixed 返回响应数据，或 null 表示已发送响应
     */
    public function handle(Context $ctx, callable $next): mixed;
}
