<?php

namespace OmniPHP;

/**
 * 日志切割（PHP 自实现，不依赖 logrotate / cron）
 *
 * 两类日志的处理方式：
 *
 *   1) **FileEngine 写的日志**（verbose/debug/info/warning/error_YYYYMMDD.log）
 *      已是日期后缀文件名，每天自然写新文件。FileEngine::cleanup() 删超过 maxDays 天的。
 *      本类不动它们——但会触发 cleanup 帮忙清扫（保险）。
 *
 *   2) **长进程持 fd 的日志**（workerman.log、madelineproto.log、stdout.log、
 *      worker.log、worker_stdout.log、crash-fallback.log）
 *      没有日期后缀，由 Workerman/MadelineProto 自己写。我们用 **copy + truncate**
 *      策略：copy 到 <name>_YYYYMMDD.log，然后 ftruncate 原文件到 0。
 *      原 fd 还是同一 inode，只是 size 重置——进程无感知，不需要重启。
 *      代价：truncate 那一瞬间正在写的若干字节可能丢，对运维日志可接受。
 *
 * 触发方式：
 *   - server.php / worker.php 启动时注册 Workerman Timer，每小时跑一次 rotateIfNeeded()
 *     （内部 idempotent——同一天多次调用只会 archive 一次）
 *   - 或手动调 LogRotator::rotate() 立刻执行
 *
 * 保留策略：所有日志统一 5 天（可改 telegram.logging.keep_days 或 env LOG_KEEP_DAYS）。
 */
class LogRotator
{
    /**
     * 长进程持 fd 的日志清单（基础名，不含路径）
     * 这些文件 Workerman / MadelineProto 直接写，需要 copy+truncate 切割
     */
    private const LONG_LIVED_LOGS = [
        'workerman.log',          // server.php 的 Workerman master log
        'worker.log',             // worker.php 的 Workerman master log
        'stdout.log',             // server.php daemon 模式 stdout/stderr 重定向
        'worker_stdout.log',      // worker.php daemon 模式同上
        'madelineproto.log',      // MadelineProto 自己写
        'crash-fallback.log',     // bootstrap 全局错误兜底
        'test_revolt_ipc.log',    // 测试脚本副产物（可选）
        'test_revolt_ipc_stdout.log',
    ];

    /** 最近一次成功 rotate 的日期（'Ymd'），避免一天多次重复 archive */
    private static string $lastRotateDate = '';

    /**
     * 检查是否到了新一天，是就 rotate（推荐定时调用）
     */
    public static function rotateIfNeeded(?int $keepDays = null): array
    {
        $today = date('Ymd');
        if (self::$lastRotateDate === $today) {
            return ['skipped' => true, 'reason' => 'already rotated today'];
        }
        return self::rotate($keepDays);
    }

    /**
     * 强制 rotate（不检查日期，常用于手动测试）
     *
     * @return array{archived: int, deleted: int, errors: string[]}
     */
    public static function rotate(?int $keepDays = null): array
    {
        if (!defined('RUNTIME_PATH')) {
            throw new \RuntimeException('LogRotator requires the application to define RUNTIME_PATH before use');
        }
        $logDir = RUNTIME_PATH . '/logs';
        if (!is_dir($logDir)) {
            return ['archived' => 0, 'deleted' => 0, 'errors' => ['log dir not found: ' . $logDir]];
        }

        $keepDays = $keepDays
            ?? (int)(getenv('LOG_KEEP_DAYS') ?: Config::get('telegram.logging.keep_days', 5));
        $keepDays = max(1, $keepDays);

        $stats = ['archived' => 0, 'deleted' => 0, 'errors' => []];

        // ── 步骤 1：archive 长进程持 fd 的日志 ──────────────────────
        // 用昨天的日期当后缀（archive 的是"截止到昨晚 24:00 那批日志"）
        $yesterdayStr = date('Ymd', strtotime('yesterday'));

        foreach (self::LONG_LIVED_LOGS as $name) {
            $src = $logDir . '/' . $name;
            if (!file_exists($src) || filesize($src) === 0) {
                continue;
            }

            $base = pathinfo($name, PATHINFO_FILENAME);   // workerman / madelineproto / ...
            $ext  = pathinfo($name, PATHINFO_EXTENSION);  // log
            $archive = $logDir . '/' . $base . '_' . $yesterdayStr . '.' . $ext;

            // 已经 archive 过昨天就不重复（防一天调多次或跨午夜重复）
            if (file_exists($archive)) {
                continue;
            }

            try {
                // copy 到归档名
                if (!@copy($src, $archive)) {
                    $stats['errors'][] = "copy failed: {$name}";
                    continue;
                }

                // truncate 原文件——保留 inode，长进程的 fd 还是有效，下次 write 从 0 开始
                $fp = @fopen($src, 'r+');
                if ($fp) {
                    @flock($fp, LOCK_EX);
                    @ftruncate($fp, 0);
                    @flock($fp, LOCK_UN);
                    @fclose($fp);
                    $stats['archived']++;
                } else {
                    // 没法 truncate 也不删 archive，下次重跑
                    $stats['errors'][] = "truncate failed (fopen): {$name}";
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "archive {$name}: " . $e->getMessage();
            }
        }

        // ── 步骤 2：删除所有超过 keepDays 天的 *_YYYYMMDD.log ────────
        // 同时覆盖 FileEngine 写的（error_20260514.log）和上面 archive 的（workerman_20260514.log）
        $cutoffTs = time() - $keepDays * 86400;
        $candidates = glob($logDir . '/*_*.log') ?: [];

        foreach ($candidates as $file) {
            $name = basename($file);
            // 只清"看起来像 xxx_YYYYMMDD.log"格式的——避免误删活跃的主日志
            if (!preg_match('/_\d{8}\.log$/', $name)) {
                continue;
            }
            if (filemtime($file) >= $cutoffTs) {
                continue; // 还没过期
            }
            if (@unlink($file)) {
                $stats['deleted']++;
            } else {
                $stats['errors'][] = "delete failed: {$name}";
            }
        }

        self::$lastRotateDate = date('Ymd');

        Logger::info('[LogRotator] rotated', [
            'archived'   => $stats['archived'],
            'deleted'    => $stats['deleted'],
            'errors'     => count($stats['errors']),
            'keep_days'  => $keepDays,
        ]);

        return $stats;
    }
}
