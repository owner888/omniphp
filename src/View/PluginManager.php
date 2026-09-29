<?php
/**
 * 插件管理器
 * 
 * 管理模板插件的加载和执行
 * 支持三种插件类型：
 * - function: 函数插件
 * - modifier: 修饰符插件
 * - block: 块插件
 */

namespace OmniPHP\View;

use OmniPHP\Logger;

class PluginManager
{
    protected string $pluginDir = '';
    protected array $loadedPlugins = [];
    
    // 危险函数黑名单（绝对禁止）
    const FORBIDDEN_FUNCTIONS = [
        'eval', 'assert', 'create_function',
        'system', 'exec', 'shell_exec', 'passthru',
        'proc_open', 'popen', 'pcntl_exec'
    ];
    
    public function __construct(string $pluginDir)
    {
        $this->pluginDir = rtrim($pluginDir, '/');
        
        // 确保插件目录存在
        if (!is_dir($this->pluginDir)) {
            mkdir($this->pluginDir, 0755, true);
        }
    }
    
    /**
     * 加载 function 插件
     */
    public function loadFunction(string $name): bool
    {
        return $this->loadPlugin('function', $name);
    }
    
    /**
     * 加载 modifier 插件
     */
    public function loadModifier(string $name): bool
    {
        return $this->loadPlugin('modifier', $name);
    }
    
    /**
     * 加载 block 插件
     */
    public function loadBlock(string $name): bool
    {
        return $this->loadPlugin('block', $name);
    }
    
    /**
     * 加载插件文件（带安全检查）
     */
    protected function loadPlugin(string $type, string $name): bool
    {
        $key = "{$type}.{$name}";
        
        // 已加载，跳过
        if (isset($this->loadedPlugins[$key])) {
            return true;
        }
        
        $file = $this->pluginDir . "/{$type}.{$name}.php";
        
        if (!file_exists($file)) {
            return false;
        }
        
        // 安全检查：检测危险函数
        $securityCheck = $this->checkPluginSecurity($file);
        if (!$securityCheck['safe']) {
            Logger::error("🚫 插件安全检查失败: {$file} - " . $securityCheck['error']);
            throw new \Exception("Plugin security check failed: {$name} - " . $securityCheck['error']);
        }
        
        require_once $file;
        $this->loadedPlugins[$key] = true;
        
        return true;
    }
    
    /**
     * 检查插件安全性
     */
    protected function checkPluginSecurity(string $file): array
    {
        $content = file_get_contents($file);
        
        // 检测危险函数
        foreach (self::FORBIDDEN_FUNCTIONS as $func) {
            if (preg_match("/\b{$func}\s*\(/", $content)) {
                return [
                    'safe' => false,
                    'error' => "Forbidden function detected: {$func}()"
                ];
            }
        }
        
        // 检测反引号命令执行
        if (preg_match('/`[^`]+`/', $content)) {
            return [
                'safe' => false,
                'error' => 'Backtick command execution detected'
            ];
        }
        
        return ['safe' => true, 'error' => ''];
    }
    
    /**
     * 调用 function 插件
     */
    public function callFunction(string $name, ViewEngine $engine, array $params): string
    {
        $this->loadFunction($name);
        
        $funcName = "tpl_function_{$name}";
        
        if (!function_exists($funcName)) {
            return "<!-- Function plugin '{$name}' not found -->";
        }
        
        return (string)$funcName($engine, $params);
    }
    
    /**
     * 调用 modifier 插件
     */
    public function callModifier(string $name, mixed $value, ...$args): mixed
    {
        $this->loadModifier($name);
        
        $funcName = "tpl_modifier_{$name}";
        
        if (!function_exists($funcName)) {
            return $value;
        }
        
        return $funcName($value, ...$args);
    }
    
    /**
     * 调用 block 插件
     */
    public function callBlock(string $name, ViewEngine $engine, array $params): array
    {
        $this->loadBlock($name);
        
        $funcName = "tpl_block_{$name}";
        
        if (!function_exists($funcName)) {
            return [];
        }
        
        $result = $funcName($engine, $params);
        
        return is_array($result) ? $result : [];
    }
    
    /**
     * 获取插件目录
     */
    public function getPluginDir(): string
    {
        return $this->pluginDir;
    }
    
    /**
     * 检查插件是否存在
     */
    public function pluginExists(string $type, string $name): bool
    {
        $file = $this->pluginDir . "/{$type}.{$name}.php";
        return file_exists($file);
    }
}
