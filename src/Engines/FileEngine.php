<?php

namespace OmniPHP\Engines;

use OmniPHP\Config;
use OmniPHP\LoggerEngineInterface;

/**
 * File Logger Engine
 * 
 * 按天写入日志文件：{level}_{Ymd}.log（如 info_20260501.log）
 * 自动清理超过 maxDays 天的旧日志文件
 */
class FileEngine implements LoggerEngineInterface
{
    private string $logPath;
    private string $logFile;           // 基础名，如 'info'、'error'
    // 日志保留天数（0=不限）。统一通过 env LOG_KEEP_DAYS / config 控制，跟 LogRotator 同步
    private int $maxDays = 5;
    private string $dateFormat = 'Y-m-d H:i:s';
    private string $levelFormat = '[%s]';
    private bool $enabled = false;
    private bool $useLocking = true;
    private string $lastCleanupDate = '';  // 每天只清理一次

    /**
     * 构造函数
     * 
     * @param string $logPath 日志目录路径
     * @param string $logFile 日志文件基础名（不含日期和扩展名）
     */
    public function __construct(string $logPath = '', string $logFile = 'app')
    {
        $this->logPath = $logPath ?: Config::get('app.logging.path', RUNTIME_PATH . '/logs');
        $this->logFile = $logFile ?: 'app';
        
        $this->ensureLogDir();
        $this->enabled = is_dir($this->logPath) && is_writable($this->logPath);
    }

    /**
     * 发送日志到文件
     */
    public function send(string $level, string $message, string|array $context = []): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $formatted = $this->formatMessage($level, $message, $context);
        
        return $this->write($formatted);
    }

    /**
     * 写入日志文件（写入当天的日志文件）
     */
    public function write(string $content): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $filePath = $this->getLogFilePath();
        
        // 每天首次写入时清理旧日志
        $today = date('Ymd');
        if ($this->lastCleanupDate !== $today) {
            $this->lastCleanupDate = $today;
            $this->cleanup();
        }
        
        // 写入日志（追加模式）
        $flags = FILE_APPEND | ($this->useLocking ? LOCK_EX : 0);
        $result = file_put_contents($filePath, $content . PHP_EOL, $flags);
        
        return $result !== false;
    }

    /**
     * 批量写入日志
     */
    public function writeBatch(array $lines): bool
    {
        if (!$this->isAvailable() || empty($lines)) {
            return false;
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;
        return $this->write($content);
    }

    public function getName(): string
    {
        return 'File';
    }

    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function setLevelFormat(string $format): void
    {
        $this->levelFormat = $format;
    }

    public function setDateFormat(string $format): void
    {
        $this->dateFormat = $format;
    }

    /**
     * 设置日志保留天数
     * @param int $days 天数，0=不限
     */
    public function setMaxDays(int $days): void
    {
        $this->maxDays = max(0, $days);
    }

    public function setUseLocking(bool $use): void
    {
        $this->useLocking = $use;
    }

    public function getConfig(): array
    {
        return [
            'log_path' => $this->logPath,
            'log_file' => $this->logFile,
            'max_days' => $this->maxDays,
            'date_format' => $this->dateFormat,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * 获取当天日志文件路径
     * 格式: runtime/logs/info_20260501.log
     */
    public function getLogFilePath(?string $date = null): string
    {
        $d = $date ?: date('Ymd');
        return $this->logPath . '/' . $this->logFile . '_' . $d . '.log';
    }

    /**
     * 获取日志基础名
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    /**
     * 列出该级别所有可用的日志日期（降序）
     * 返回: ['20260501', '20260430', ...]
     */
    public function listDates(): array
    {
        $pattern = $this->logPath . '/' . $this->logFile . '_*.log';
        $files = glob($pattern);
        
        if (empty($files)) {
            return [];
        }

        $dates = [];
        $prefix = $this->logFile . '_';
        foreach ($files as $file) {
            $basename = basename($file, '.log');
            $date = str_replace($prefix, '', $basename);
            // 验证是 8 位日期格式
            if (preg_match('/^\d{8}$/', $date)) {
                $dates[] = $date;
            }
        }
        
        rsort($dates); // 最新在前
        return $dates;
    }

    /**
     * 读取指定日期的日志文件末尾 N 行
     */
    public function read(int $lines = 100, ?string $date = null): array
    {
        $filePath = $this->getLogFilePath($date);
        
        if (!file_exists($filePath)) {
            return [];
        }

        return $this->tailFile($filePath, $lines);
    }

    /**
     * 搜索指定日期的日志
     */
    public function search(string $keyword, int $limit = 100, ?string $date = null): array
    {
        $filePath = $this->getLogFilePath($date);
        
        if (!file_exists($filePath)) {
            return [];
        }

        // 在最近 5000 行中搜索
        $allLines = $this->tailFile($filePath, 5000);
        $matched = [];
        foreach ($allLines as $line) {
            if (stripos($line, $keyword) !== false) {
                $matched[] = $line;
            }
        }
        return array_slice($matched, -$limit);
    }

    /**
     * 获取指定日期的日志文件大小
     */
    public function getFileSize(?string $date = null): int
    {
        $filePath = $this->getLogFilePath($date);
        return file_exists($filePath) ? filesize($filePath) : 0;
    }

    /**
     * 清空指定日期的日志文件
     */
    public function clear(?string $date = null): bool
    {
        $filePath = $this->getLogFilePath($date);
        
        if (!file_exists($filePath)) {
            return true;
        }

        return file_put_contents($filePath, '') !== false;
    }

    /**
     * 删除超过 maxDays 天的旧日志文件
     */
    public function cleanup(): int
    {
        if ($this->maxDays <= 0) {
            return 0;
        }

        $pattern = $this->logPath . '/' . $this->logFile . '_*.log';
        $files = glob($pattern);
        
        if (empty($files)) {
            return 0;
        }

        $deleted = 0;
        $cutoff = time() - ($this->maxDays * 86400);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }

    // ========================================
    // Private helpers
    // ========================================

    private function formatMessage(string $level, string $message, string|array $context = []): string
    {
        $timestamp = date($this->dateFormat);
        $levelStr = sprintf($this->levelFormat, strtoupper($level));
        
        $formatted = "[{$timestamp}] {$levelStr} - {$message}";
        
        if (!empty($context)) {
            $contextStr = is_array($context) 
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : $context;
            $formatted .= PHP_EOL . $contextStr;
        }
        
        return $formatted;
    }

    private function ensureLogDir(): void
    {
        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * 高效读取文件末尾 N 行
     */
    private function tailFile(string $filePath, int $lines): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            fclose($handle);
            return [];
        }

        $buffer = '';
        $lineCount = 0;
        $chunkSize = 8192;
        $pos = $fileSize;

        while ($pos > 0 && $lineCount <= $lines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        $allLines = explode("\n", $buffer);
        $allLines = array_filter($allLines, fn($l) => trim($l) !== '');
        $allLines = array_values($allLines);

        return array_slice($allLines, -$lines);
    }
}
