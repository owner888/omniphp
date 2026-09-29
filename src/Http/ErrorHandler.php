<?php
/**
 * 统一错误处理辅助类
 * 
 * 提供标准化的错误处理、日志记录和响应格式化
 */

namespace OmniPHP\Http;

use OmniPHP\Logger;
use Exception;
use Throwable;

class ErrorHandler
{
    /**
     * 处理异常并返回标准错误响应
     * 
     * @param Throwable $e 异常对象
     * @param string $context 上下文描述（用于日志）
     * @param array $extra 额外的上下文信息
     * @return array 标准错误响应格式
     */
    public static function handle(Throwable $e, string $context = '', array $extra = []): array
    {
        // 构造日志消息
        $message = $context ? "{$context}: {$e->getMessage()}" : $e->getMessage();
        
        // 记录详细错误日志
        self::logError($e, $context, $extra);
        
        // 返回标准错误响应
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_code' => self::getErrorCode($e),
            'context' => $context ?: 'Unknown',
        ];
    }
    
    /**
     * 记录错误日志
     */
    public static function logError(Throwable $e, string $context = '', array $extra = []): void
    {
        $errorInfo = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $context,
            'extra' => $extra,
        ];
        
        // 记录到日志
        Logger::error($context ? "{$context}: {$e->getMessage()}" : $e->getMessage(), $errorInfo);
        
        // 堆栈跟踪（由 LOG_LEVEL 控制是否输出）
        Logger::debug("Stack trace: " . $e->getTraceAsString());
    }
    
    /**
     * 记录操作日志
     */
    public static function logOperation(string $operation, string $status, array $details = []): void
    {
        $message = "[{$operation}] {$status}";
        
        if ($status === 'success' || $status === '成功') {
            Logger::info($message, $details);
        } elseif ($status === 'warning' || $status === '警告') {
            Logger::warning($message, $details);
        } else {
            Logger::error($message, $details);
        }
    }
    
    /**
     * 获取错误代码
     */
    private static function getErrorCode(Throwable $e): string
    {
        $code = $e->getCode();
        
        if ($code && is_numeric($code)) {
            return "ERR_{$code}";
        }
        
        // 根据异常类型生成代码
        $className = basename(str_replace('\\', '/', get_class($e)));
        return strtoupper(preg_replace('/Exception$/', '', $className));
    }
    
    /**
     * 验证必需参数
     * 
     * @throws Exception 如果参数缺失
     */
    public static function requireParams(array $params, array $required): void
    {
        $missing = [];
        
        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            $fields = implode(', ', $missing);
            throw new Exception("Missing required parameters: {$fields}");
        }
    }
    
    /**
     * 安全执行代码块
     * 
     * @param callable $callback 要执行的回调
     * @param string $context 上下文描述
     * @param mixed $defaultReturn 失败时返回的默认值
     * @return mixed 回调返回值或默认值
     */
    public static function tryCatch(callable $callback, string $context = '', $defaultReturn = null)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            self::logError($e, $context);
            return $defaultReturn;
        }
    }
    
    /**
     * 创建成功响应
     */
    public static function success(array $data = [], string $message = ''): array
    {
        $response = [
            'success' => true,
            'data' => $data,
        ];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        return $response;
    }
    
    /**
     * 创建错误响应
     */
    public static function error(string $message, string $code = 'ERROR', array $details = []): array
    {
        return [
            'success' => false,
            'error' => $message,
            'error_code' => $code,
            'details' => $details ?: null,
        ];
    }
    
    /**
     * 验证文件存在
     * 
     * @throws Exception 如果文件不存在
     */
    public static function requireFile(string $path, string $description = 'File'): void
    {
        if (!file_exists($path)) {
            throw new Exception("{$description} not found: {$path}");
        }
    }
    
    /**
     * 验证目录可写
     * 
     * @throws Exception 如果目录不可写
     */
    public static function requireWritableDir(string $path): void
    {
        if (!is_dir($path)) {
            throw new Exception("Directory not found: {$path}");
        }
        
        if (!is_writable($path)) {
            throw new Exception("Directory not writable: {$path}");
        }
    }
    
    /**
     * 记录性能指标
     */
    public static function logPerformance(string $operation, float $duration, array $metrics = []): void
    {
        $metrics['duration_ms'] = round($duration, 2);
        $message = "[Performance] {$operation}: {$metrics['duration_ms']}ms";
        
        // 超过1秒的操作记录为警告
        if ($duration > 1000) {
            Logger::warning($message, $metrics);
        } else {
            Logger::info($message, $metrics);
        }
    }
    
    /**
     * 包装方法调用，自动处理异常
     * 
     * @param callable $callback 要执行的回调函数
     * @param string $operationName 操作名称（用于日志）
     * @param array $context 上下文信息
     * @return mixed 回调返回值或错误响应
     */
    public static function wrap(callable $callback, string $operationName = '', array $context = [])
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return self::handle($e, $operationName, $context);
        }
    }
}
