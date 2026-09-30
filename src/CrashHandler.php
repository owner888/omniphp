<?php

namespace OmniPHP;

use Throwable;

/**
 * 全局 fatal / uncaught 兜底（Workerman + CLI 共用）
 *
 * 痛点：Workerman 子进程崩了 master 静默 fork 新的，错误信息只去 stderr（daemon 模式
 *      混进 stdout.log），没栈、没时间戳、没 worker 上下文。用 Logger 走的错误能进
 *      runtime/logs/error.log，但 fatal/uncaught 根本到不了 Logger。
 *
 * 装两个全局钩子：
 *   1. set_exception_handler      → 接 throw 出去没人 catch 的 Throwable
 *   2. register_shutdown_function → 接 ParseError / OOM / TypeError 等真 fatal
 *
 * 三层写入（任何一层崩了下一层接力，从不丢）：
 *   Tier 1: Logger::error        → error.log + 应用注册的告警引擎（理想路径）
 *   Tier 2: stderr fwrite        → 前台或 daemon stdout.log（Logger 崩了用）
 *   Tier 3: file_put_contents    → runtime/logs/crash-fallback.log（连 stderr 都失败用）
 *
 * 死循环防护：handler 重入锁、shutdown 重入锁、shutdown 对 exception_handler 已处理的
 * error 按签名去重、Tier 2/3 完全绕开 Logger 调用栈。应用侧的日志引擎若会回调
 * Logger（如 Webhook 引擎），需自带重入锁。
 */
final class CrashHandler
{
    private static bool $installed = false;
    private static bool $inException = false;
    private static bool $inShutdown = false;
    private static string $lastExceptionSig = '';

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        set_exception_handler([self::class, 'onUncaught']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onUncaught(Throwable $e): void
    {
        // 闸 1：重入。handler 内某行抛了 → PHP 又调一次 → 直接走 Tier 3 + return
        if (self::$inException) {
            self::writeCrashFallback('[Recursive Uncaught] ' . get_class($e) . ': ' . $e->getMessage());
            return;
        }
        self::$inException = true;

        // 给 shutdown handler 留个签名，去重避免双写
        self::$lastExceptionSig = $e->getFile() . ':' . $e->getLine() . ':' . $e->getMessage();

        [$procTitle, $pid] = self::procInfo();
        $line = sprintf(
            "[Uncaught] %s: %s at %s:%d worker=%s pid=%d",
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $procTitle, $pid
        );

        // Tier 2：stderr。@ 屏蔽 fd 关闭等 warning
        $stderrOk = (bool)@fwrite(STDERR, $line . "\n" . $e->getTraceAsString() . "\n");

        // Tier 1：Logger。任何抛都 catch
        try {
            Logger::error('[Uncaught] ' . get_class($e), [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'worker'  => $procTitle,
                'pid'     => $pid,
            ]);
        } catch (Throwable $loggerErr) {
            self::writeCrashFallback($line . ' | [LoggerFail] ' . $loggerErr->getMessage());
        }

        if (!$stderrOk) {
            self::writeCrashFallback($line);
        }

        self::$inException = false;
    }

    public static function onShutdown(): void
    {
        // 闸 2：shutdown 重入。PHP 本身保证只调一次，但保险
        if (self::$inShutdown) {
            return;
        }
        self::$inShutdown = true;

        $err = error_get_last();
        if (!$err) {
            return;
        }
        // 只关心真 fatal——E_WARNING / E_NOTICE 不在这里拦
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
            return;
        }

        // 闸 3：去重。PHP 7+ 行为是 exception_handler 处理过的 throw 不会出现在 error_get_last，
        //         但某些极端版本 / 错误类型可能漏；加签名比对兜底
        $sig = ($err['file'] ?? '?') . ':' . ($err['line'] ?? 0) . ':' . ($err['message'] ?? '');
        if (self::$lastExceptionSig !== ''
            && str_contains($sig, substr(self::$lastExceptionSig, 0, 60))) {
            return;
        }

        [$procTitle, $pid] = self::procInfo();
        $line = sprintf(
            "[Fatal] type=%d %s at %s:%d worker=%s pid=%d",
            $err['type'], $err['message'] ?? '?', $err['file'] ?? '?', $err['line'] ?? 0, $procTitle, $pid
        );

        // 整段再包一层 try——shutdown 阶段抛出去就直接 PHP 死，没人接
        try {
            $stderrOk = (bool)@fwrite(STDERR, $line . "\n");

            try {
                Logger::error('[Fatal] ' . ($err['message'] ?? ''), [
                    'type'   => $err['type'],
                    'file'   => ($err['file'] ?? '?') . ':' . ($err['line'] ?? 0),
                    'worker' => $procTitle,
                    'pid'    => $pid,
                ]);
            } catch (Throwable $loggerErr) {
                self::writeCrashFallback($line . ' | [LoggerFail] ' . $loggerErr->getMessage());
            }

            if (!$stderrOk) {
                self::writeCrashFallback($line);
            }
        } catch (Throwable $shutdownErr) {
            // shutdown handler 自己抛 → 只走 Tier 3，不能再让任何东西抛
            self::writeCrashFallback($line . ' | [ShutdownFail] ' . $shutdownErr->getMessage());
        }
    }

    /** @return array{0: string, 1: int} [进程标题, pid] */
    private static function procInfo(): array
    {
        $procTitle = function_exists('cli_get_process_title') ? (cli_get_process_title() ?: 'cli') : 'cli';
        $pid       = function_exists('posix_getpid') ? posix_getpid() : (int)getmypid();
        return [$procTitle, $pid];
    }

    /** Tier 3：直接写崩溃兜底日志（不经过 Logger）。mkdir / open 失败一律静默——这本来就是最后的兜底 */
    private static function writeCrashFallback(string $line): void
    {
        static $path = null;
        if ($path === null) {
            $path = RUNTIME_PATH . '/logs/crash-fallback.log';
            @mkdir(dirname($path), 0755, true);
        }
        @file_put_contents(
            $path,
            '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
