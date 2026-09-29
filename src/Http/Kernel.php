<?php

namespace OmniPHP\Http;

use OmniPHP\Logger;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * HTTP 内核：Workerman onMessage → Router::dispatch → 发送响应 + access log
 *
 * 用法（server.php）：
 *   require BASE_PATH . '/routes.php';            // 路由在 master 里注册一次，fork 后子进程继承
 *   $http->onMessage = [Kernel::class, 'handle'];
 *
 * handler 返回值约定：
 *   - null      → handler 已自行发送响应（如 SSE / 文件流）
 *   - Response  → 原样发送，access log 记其真实状态码
 *   - array     → JSON 发送；含 'error' 键视为 404，其余 200（stripNulls 见 Context）
 */
final class Kernel
{
    private const JSON_HEADERS = ['Content-Type' => 'application/json; charset=utf-8'];

    public static function handle(TcpConnection $connection, Request $request): void
    {
        $startTime = RequestLogger::start($request);

        try {
            $result = Router::dispatch($connection, $request);

            if ($result === null) {
                RequestLogger::end($request, 200, $startTime, $connection);
                return;
            }

            if ($result instanceof Response) {
                $connection->send($result);
                RequestLogger::end($request, $result->getStatusCode(), $startTime, $connection);
                return;
            }

            $statusCode = isset($result['error']) ? 404 : 200;
            $connection->send(new Response(
                $statusCode,
                self::JSON_HEADERS,
                json_encode(Context::stripNulls($result), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ));
            RequestLogger::end($request, $statusCode, $startTime, $connection);
        } catch (Throwable $e) {
            // 只有没注册 ExceptionHandler、或 ExceptionHandler 自己抛了才会到这里。
            // Throwable 而非 Exception：TypeError 等 \Error 也要接住，
            // 否则会漏到 Workerman Fiber driver 的 errorHandler → stopAll(250) 整组重启。
            // 细节只进日志，不回给客户端——调试信息由 ExceptionHandler(debug: true) 负责。
            Logger::error('[Kernel] Unhandled ' . get_class($e) . ': ' . $e->getMessage(), [
                'path' => $request->path(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $connection->send(new Response(500, self::JSON_HEADERS, json_encode(['error' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE)));
            RequestLogger::end($request, 500, $startTime, $connection);
        }
    }
}
