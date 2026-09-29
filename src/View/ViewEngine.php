<?php
/**
 * 轻量级模板引擎（基于 QuickTag 重写，适配 Workerman）
 * 
 * 特性：
 * - 模板编译和缓存
 * - 支持变量赋值
 * - 自动检测模板变更
 * - 线程安全（适配 Workerman）
 */

namespace OmniPHP\View;

use OmniPHP\Config;

class ViewEngine
{
    // 目录配置
    protected string $templateDir = '';
    protected string $compileDir = '';
    protected string $cacheDir = '';
    protected string $pluginDir = '';
    
    // 编译配置
    protected string $compilePwd = '';
    protected bool $forceCompile = false;
    
    // 缓存配置
    protected bool $isCaching = false;
    protected int $cacheLifetime = 3600;
    
    // 标签格式
    protected string $leftDelimiter = '{{';
    protected string $rightDelimiter = '}}';
    
    // 模板变量
    protected array $tplVars = [];
    
    // 插件管理器
    public ?PluginManager $pluginManager = null;
    
    // 当前文件路径
    protected string $tplFile = '';
    protected string $compileFile = '';
    protected string $cacheFile = '';
    
    /**
     * 构造函数
     */
    public function __construct(array $config = [])
    {
        // 直接从Config读取配置（支持传参覆盖）
        $this->templateDir = $config['template_dir'] ?? Config::get('view.template_dir', '');
        $this->compileDir = $config['compile_dir'] ?? Config::get('view.compile_dir', '');
        $this->cacheDir = $config['cache_dir'] ?? Config::get('view.cache_dir', '');
        $this->pluginDir = $config['plugin_dir'] ?? Config::get('view.plugin_dir', '');
        $this->forceCompile = $config['force_compile'] ?? Config::get('view.force_compile', false);
        $this->isCaching = $config['is_caching'] ?? Config::get('view.is_caching', false);
        $this->cacheLifetime = $config['cache_lifetime'] ?? Config::get('view.cache_lifetime', 3600);
        
        // 确保目录存在
        $this->ensureDirectory($this->templateDir);
        $this->ensureDirectory($this->compileDir);
        $this->ensureDirectory($this->cacheDir);
        
        // 初始化插件管理器（如果配置了插件目录）
        if (!empty($this->pluginDir)) {
            $this->pluginManager = new PluginManager($this->pluginDir);
        }
    }
    
    /**
     * 确保目录存在
     */
    protected function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    /**
     * 传递变量给模板
     */
    public function assign(string|array $tplVar, mixed $value = null): self
    {
        if (is_array($tplVar)) {
            foreach ($tplVar as $key => $val) {
                if ($key !== '') {
                    $this->tplVars[$key] = $val;
                }
            }
        } else {
            if ($tplVar !== '') {
                $this->tplVars[$tplVar] = $value;
            }
        }
        return $this;
    }
    
    /**
     * 清除已赋值的模板变量
     */
    public function clearAssign(string|array $tplVar): self
    {
        if (is_array($tplVar)) {
            foreach ($tplVar as $currVar) {
                unset($this->tplVars[$currVar]);
            }
        } else {
            unset($this->tplVars[$tplVar]);
        }
        return $this;
    }
    
    /**
     * 重置所有模板变量
     */
    public function resetAssign(): self
    {
        $this->tplVars = [];
        return $this;
    }
    
    /**
     * 获取编译密码（用于文件名加密）
     */
    protected function getCompilePwd(): string
    {
        if ($this->compilePwd === '') {
            $cacheFile = $this->compileDir . '/.compile_pwd';
            
            if (file_exists($cacheFile)) {
                $this->compilePwd = trim(file_get_contents($cacheFile));
            }
            
            if ($this->compilePwd === '') {
                $this->compilePwd = bin2hex(random_bytes(8));
                file_put_contents($cacheFile, $this->compilePwd);
            }
        }
        return $this->compilePwd;
    }
    
    /**
     * 获取模板资源信息
     */
    protected function fetchResourceInfo(string $tplName): array
    {
        $infos = [
            'tpl_cache' => '',
            'save_cache' => false,
            'cache_data' => ''
        ];
        
        // 自动添加 .tpl 扩展名（如果没有）
        if (!str_ends_with($tplName, '.tpl')) {
            $tplName .= '.tpl';
        }
        
        $this->tplFile = $this->templateDir . '/' . $tplName;
        
        // 生成编译文件名
        $tplNames = preg_split("/[\/\\\\]/", $tplName);
        $comFile = str_replace('.tpl', '_' . $this->getCompilePwd() . '.php', implode('__', $tplNames));
        $comFile = str_replace('..', '__', $comFile);
        $this->compileFile = $this->compileDir . '/' . $comFile;
        
        // 生成缓存文件名
        $this->cacheFile = $this->cacheDir . '/' . preg_replace("/\.([^\.]*)$/", ".html", $tplName);
        
        // 检查缓存（管理后台 admin/ 与通用组件 common/、auth/ 涉及实时交互，跳过全页静态 HTML 缓存）
        $isDynamicTpl = str_starts_with($tplName, 'admin/') || str_starts_with($tplName, 'common/') || str_starts_with($tplName, 'auth/');
        if ($this->isCaching && !$isDynamicTpl) {
            if (file_exists($this->cacheFile) && 
                (time() - filemtime($this->cacheFile)) < $this->cacheLifetime) {
                $infos['cache_data'] = file_get_contents($this->cacheFile);
            } else {
                $infos['save_cache'] = true;
            }
        }
        
        // 检查是否需要编译
        if ($infos['cache_data'] === '' && file_exists($this->tplFile)) {
            if ($this->forceCompile || 
                !file_exists($this->compileFile) || 
                filemtime($this->compileFile) < filemtime($this->tplFile)) {
                
                // 执行编译（传递必要的配置信息）
                $compiler = new ViewCompiler(
                    $this->templateDir,
                    $this->compileDir,
                    $this->getCompilePwd(),
                    $this->pluginDir,
                    $this->leftDelimiter,
                    $this->rightDelimiter
                );
                $rs = $compiler->compile($this->tplFile, $this->compileFile);
                
                if ($rs) {
                    $infos['tpl_cache'] = $this->compileFile;
                }
            } else {
                $infos['tpl_cache'] = $this->compileFile;
            }
        }
        
        return $infos;
    }
    
    /**
     * 渲染模板并返回内容
     */
    public function fetch(string $tplName): string
    {
        $infos = $this->fetchResourceInfo($tplName);
        
        // 如果有缓存数据，直接返回
        if (!empty($infos['cache_data'])) {
            return $infos['cache_data'];
        }
        
        if (!file_exists($infos['tpl_cache'])) {
            throw new \RuntimeException("Template not found: {$tplName}");
        }
        
        // 提取模板变量到当前作用域
        extract($this->tplVars, EXTR_SKIP);
        
        // 捕获输出
        ob_start();
        require $infos['tpl_cache'];
        $content = ob_get_clean();
        
        // 保存缓存
        if ($infos['save_cache']) {
            $cacheDir = dirname($this->cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents($this->cacheFile, $content);
        }
        
        return $content;
    }
    
    /**
     * 渲染模板并直接输出
     */
    public function display(string $tplName): void
    {
        echo $this->fetch($tplName);
    }
    
    /**
     * 保存模板内容为 HTML 文件
     */
    public function saveHtml(string $tplName, string $toFile): bool
    {
        try {
            $content = $this->fetch($tplName);
            
            $dir = dirname($toFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            return file_put_contents($toFile, $content) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 清理编译文件
     */
    public function clearCompiled(): bool
    {
        return $this->clearDirectory($this->compileDir, '.compile_pwd');
    }
    
    /**
     * 清理缓存文件
     */
    public function clearCache(): bool
    {
        return $this->clearDirectory($this->cacheDir);
    }
    
    /**
     * 清理目录
     */
    protected function clearDirectory(string $dir, string $except = ''): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === $except) {
                continue;
            }
            
            $filePath = $dir . '/' . $file;
            if (is_file($filePath)) {
                unlink($filePath);
            } elseif (is_dir($filePath)) {
                $this->clearDirectory($filePath);
                rmdir($filePath);
            }
        }
        
        return true;
    }
}
