<?php
/**
 * 多语言类
 * 
 * 支持 .ini 文件格式 + 多级回退
 * 
 * 用法：
 *   Lang::init(['default' => 'zh-cn', 'fallback' => 'en']);
 *   Lang::load('common');
 *   Lang::get('user_management');          // → "用户管理"
 *   Lang::get('hello', null, ['name' => 'John']);  // → "你好, John"
 *   Lang::get('not_exist', '默认值');      // → "默认值"
 * 
 * .ini 文件格式 ({lang_dir}/zh-cn/common.ini)：
 *   user_management = "用户管理"
 *   book_management = "书籍管理"
 *   hello = "你好, {name}"
 *   create_success = "创建成功"
 */

namespace OmniPHP;

class Lang
{
    /** 当前语言 */
    private static string $locale = 'en';
    
    /** 默认语言 */
    private static string $default = 'en';
    
    /** 回退语言 */
    private static string $fallback = 'en';
    
    /** 语言文件目录 */
    private static string $langDir = '';
    
    /** 已加载的翻译 */
    private static array $translations = [];
    
    /** 已加载的文件（避免重复加载） */
    private static array $loadedFiles = [];

    /** 是否初始化 */
    private static bool $initialized = false;

    /** always_load 列表（init 时记下，switchLocale 时重新全部加载） */
    private static array $alwaysLoad = [];
    
    /**
     * 初始化
     * 
     * @param array $config [
     *   'default'  => 'zh-cn',       // 默认语言
     *   'fallback' => 'en',          // 回退语言
     *   'lang_dir' => '/path/to/lang', // 语言文件目录
     *   'always_load' => ['common'], // 自动加载的语言文件
     * ]
     */
    public static function init(array $config = []): void
    {
        self::$default  = $config['default'] ?? 'en';
        self::$fallback = $config['fallback'] ?? 'en';
        self::$locale   = $config['default'] ?? 'en';
        self::$langDir  = $config['lang_dir'] ?? throw new \InvalidArgumentException('Lang::init() requires lang_dir');
        
        self::$initialized = true;

        // 记下 always_load 列表（switchLocale 切换语言时要全部重新加载，而不只是 common）
        if (!empty($config['always_load'])) {
            self::$alwaysLoad = array_values((array) $config['always_load']);
        }

        // 自动加载指定的语言文件
        foreach (self::$alwaysLoad as $file) {
            self::load($file);
        }
    }
    
    /**
     * 设置当前语言
     */
    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }
    
    /**
     * 切换语言（重置翻译缓存并重新加载）
     * 
     * Workerman 下进程常驻，Lang 是静态类，翻译缓存会跨请求保留。
     * 每个请求需要调用此方法来切换到用户选择的语言。
     */
    public static function switchLocale(string $locale): void
    {
        if ($locale === self::$locale) {
            return;
        }
        self::$locale = $locale;
        // 重置翻译缓存，强制重新加载
        self::$translations = [];
        self::$loadedFiles = [];
        // 重新加载所有 always_load 配置的语言文件
        // （以前只加载 common，导致模块自己加的 ini（如 telegram.ini）切换语言后丢失翻译）
        $toReload = !empty(self::$alwaysLoad) ? self::$alwaysLoad : ['common'];
        foreach ($toReload as $file) {
            self::load($file);
        }
    }
    
    /**
     * 获取当前语言
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }
    
    /**
     * 加载语言文件
     * 
     * 按优先级加载：当前语言 → 默认语言 → 回退语言
     * 后加载的会覆盖先加载的（所以当前语言优先级最高）
     * 
     * @param string|array $langFile 语言文件名（不含 .ini 后缀）
     */
    public static function load(string|array $langFile): void
    {
        if (!self::$initialized) {
            self::init();
        }
        
        if (is_array($langFile)) {
            foreach ($langFile as $file) {
                self::load($file);
            }
            return;
        }
        
        $langFile = str_replace('.ini', '', $langFile);
        $iniFile = $langFile . '.ini';
        
        // 按优先级从低到高加载（后加载的覆盖前面的）
        $locales = array_unique(array_filter([
            self::$fallback,  // 最低优先级
            self::$default,   // 中间优先级
            self::$locale,    // 最高优先级
        ]));
        
        foreach ($locales as $locale) {
            $filepath = self::$langDir . '/' . $locale . '/' . $iniFile;
            
            // 避免重复加载
            $cacheKey = $filepath . ':' . $locale;
            if (isset(self::$loadedFiles[$cacheKey])) {
                continue;
            }
            
            if (file_exists($filepath)) {
                $parsed = parse_ini_file($filepath);
                if ($parsed !== false) {
                    // 合并翻译（后加载覆盖先加载）
                    self::$translations = array_merge(self::$translations, $parsed);
                    self::$loadedFiles[$cacheKey] = true;
                }
            }
        }
    }
    
    /**
     * 获取翻译文本
     * 
     * @param string $key          翻译键名
     * @param string|null $default 默认值（key 不存在时返回）
     * @param array $replace       替换参数 ['name' => 'John'] 或 ['John', 10]
     * @return string
     */
    public static function get(string $key, ?string $default = null, array $replace = []): string
    {
        if (!self::$initialized) {
            self::init();
        }
        
        // 查找翻译（忽略大小写）
        $lowerKey = strtolower($key);
        $value = self::$translations[$key] ?? self::$translations[$lowerKey] ?? null;
        
        // 找不到翻译
        if ($value === null) {
            if ($default !== null) {
                return $default;
            }
            // 开发模式返回 key 本身，方便排查
            return $key;
        }
        
        // 替换占位符
        if (!empty($replace)) {
            $value = self::replacePlaceholders($value, $replace);
        }
        
        return $value;
    }
    
    /**
     * 快捷方法（别名）
     */
    public static function t(string $key, ?string $default = null, array $replace = []): string
    {
        return self::get($key, $default, $replace);
    }
    
    /**
     * 检查翻译是否存在
     */
    public static function has(string $key): bool
    {
        return isset(self::$translations[$key]) || isset(self::$translations[strtolower($key)]);
    }
    
    /**
     * 手动设置翻译
     */
    public static function set(string $key, string $value): void
    {
        self::$translations[$key] = $value;
    }
    
    /**
     * 获取所有已加载的翻译
     */
    public static function all(): array
    {
        return self::$translations;
    }
    
    /**
     * 替换占位符
     * 
     * 支持两种格式：
     * - 命名占位符: "Hello, {name}" + ['name' => 'John'] → "Hello, John"
     * - 位置占位符: "Hello, %s, you are %d" + ['John', 25] → "Hello, John, you are 25"
     */
    private static function replacePlaceholders(string $value, array $replace): string
    {
        // 检查是否是关联数组（命名占位符）
        if (array_keys($replace) !== range(0, count($replace) - 1)) {
            // 命名占位符: {name} → value
            $search = [];
            $replaceValues = [];
            foreach ($replace as $k => $v) {
                $search[] = '{' . $k . '}';
                $replaceValues[] = (string) $v;
            }
            return str_replace($search, $replaceValues, $value);
        }
        
        // 位置占位符: %s, %d
        return vsprintf($value, $replace);
    }
    
    /**
     * 重置（主要用于测试）
     */
    public static function reset(): void
    {
        self::$translations = [];
        self::$loadedFiles = [];
        self::$initialized = false;
        self::$locale = 'en';
    }
}
