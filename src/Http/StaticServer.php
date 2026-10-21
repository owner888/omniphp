<?php

declare(strict_types=1);

namespace OmniPHP\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * 通用静态资源与 SPA 文件服务器 (对标 Go Gin 的 r.Static() / r.StaticFile())
 *
 * 内置防路径穿越沙箱、全格式 MIME 类型自动推导、以及 HTTP 304 (ETag/If-None-Match) 协商缓存。
 */
final class StaticServer
{
    private const array MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'mp3'  => 'audio/mpeg',
        'mp4'  => 'video/mp4',
        'ogg'  => 'audio/ogg',
        'wav'  => 'audio/wav',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
    ];

    /**
     * 尝试处理静态文件响应
     *
     * @param array{
     *     index?: string,
     *     spaFallback?: bool,
     *     cacheControl?: string,
     *     etag?: bool
     * } $options
     * @return bool 是否成功接管并发送了静态响应
     */
    public static function serve(
        TcpConnection $connection,
        Request $request,
        string $prefix,
        string $dir,
        array $options = []
    ): bool {
        $path = $request->path();
        $cleanPrefix = rtrim($prefix, '/');

        // 仅处理匹配前缀的请求 (空前缀代表根目录)
        if ($cleanPrefix !== '' && !str_starts_with($path, $cleanPrefix)) {
            return false;
        }

        $subPath = ($cleanPrefix !== '') ? substr($path, strlen($cleanPrefix)) : $path;
        if ($subPath === '' || $subPath === '/') {
            $subPath = '/' . ($options['index'] ?? 'index.html');
        }

        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return false;
        }

        $targetFile = $realDir . '/' . ltrim($subPath, '/');
        $realFile = realpath($targetFile);

        // 防路径穿越安全沙箱
        if ($realFile === false || !str_starts_with($realFile, $realDir) || !is_file($realFile)) {
            if (!empty($options['spaFallback'])) {
                $spaIndex = realpath($realDir . '/' . ($options['index'] ?? 'index.html'));
                if ($spaIndex !== false && is_file($spaIndex)) {
                    $realFile = $spaIndex;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }

        // 协商缓存检测 (HTTP 304)
        $mtime = (int)filemtime($realFile);
        $size = (int)filesize($realFile);
        $etag = sprintf('"%x-%x"', $mtime, $size);

        if (!empty($options['etag'] ?? true)) {
            $ifNoneMatch = $request->header('if-none-match');
            if ($ifNoneMatch === $etag) {
                $connection->send(new WorkermanResponse(304));
                return true;
            }
        }

        $mime = self::detectMimeType($realFile);
        $cacheControl = $options['cacheControl'] ?? 'public, max-age=86400';

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string)$size,
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ];

        $content = (string)file_get_contents($realFile);
        $connection->send(new WorkermanResponse(200, $headers, $content));

        return true;
    }

    /**
     * 单文件直出服务 (例如 /favicon.ico 或 /index.html)
     */
    public static function serveFile(
        TcpConnection $connection,
        Request $request,
        string $filepath,
        array $options = []
    ): bool {
        $realFile = realpath($filepath);
        if ($realFile === false || !is_file($realFile)) {
            return false;
        }

        $mtime = (int)filemtime($realFile);
        $size = (int)filesize($realFile);
        $etag = sprintf('"%x-%x"', $mtime, $size);

        if (!empty($options['etag'] ?? true)) {
            $ifNoneMatch = $request->header('if-none-match');
            if ($ifNoneMatch === $etag) {
                $connection->send(new WorkermanResponse(304));
                return true;
            }
        }

        $mime = self::detectMimeType($realFile);
        $cacheControl = $options['cacheControl'] ?? 'public, max-age=86400';

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string)$size,
            'ETag' => $etag,
            'Cache-Control' => $cacheControl,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ];

        $content = (string)file_get_contents($realFile);
        $connection->send(new WorkermanResponse(200, $headers, $content));

        return true;
    }

    /**
     * 根据扩展名安全推导 MIME 类型
     */
    public static function detectMimeType(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return self::MIME_TYPES[$ext] ?? 'application/octet-stream';
    }
}
