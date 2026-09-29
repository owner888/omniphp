<?php
/**
 * 模板编译器（轻量级实现）
 * 
 * ============================================================================
 * 支持的标签语法（统一使用 {{ }}）
 * ============================================================================
 * 
 * 1. 变量输出
 *    {{ $var }}
 *    {{ $array[0] }}
 *    {{ $obj->property }}
 * 
 * 2. 条件判断
 *    {{ if $condition }}
 *        内容
 *    {{ elseif $other }}
 *        其他内容
 *    {{ else }}
 *        默认内容
 *    {{ endif }}
 * 
 * 3. 循环
 *    {{ foreach $items as $item }}
 *        {{ $item }}
 *    {{ endforeach }}
 *    
 *    {{ foreach $items as $key => $value }}
 *        {{ $key }}: {{ $value }}
 *    {{ endforeach }}
 *    
 *    {{ for $i=0; $i<10; $i++ }}
 *        {{ $i }}
 *    {{ endfor }}
 * 
 * 4. 模板包含
 *    {{ include file="header.tpl" }}
 *    {{ include file="footer" }}  <!-- .tpl 可省略 -->
 * 
 * 5. 注释
 *    {{* 这是注释，不会显示在页面中 *}}
 * 
 * 6. 函数调用
 *    {{ date('Y-m-d H:i:s') }}
 *    {{ count($items) }}
 *    {{ strtoupper($text) }}
 *    
 *    在条件中使用函数：
 *    {{ if count($items) > 0 }}
 *    {{ if empty($data) }}
 *    {{ if isset($user['name']) }}
 * 
 * ============================================================================
 * 链式修饰符（Modifiers）
 * ============================================================================
 * 
 * 无参数修饰符：
 *    {{ $text|escape }}           - HTML转义（防XSS）
 *    {{ $text|nl2br }}            - 换行符转<br>
 *    {{ $text|upper }}            - 转大写
 *    {{ $text|lower }}            - 转小写
 *    {{ $text|trim }}             - 去除首尾空格
 *    {{ $text|ucfirst }}          - 首字母大写
 *    {{ $text|ucwords }}          - 每个单词首字母大写
 *    {{ $html|strip_tags }}       - 去除HTML标签
 *    {{ $url|urlencode }}         - URL编码
 *    {{ $data|json }}             - JSON编码
 * 
 * 带参数修饰符：
 *    {{ $nullable|default('无数据') }}          - 默认值
 *    {{ $price|number_format(2) }}              - 数字格式化
 *    {{ $date|date_format('Y-m-d') }}           - 日期格式化
 *    {{ $text|substr(0, 100) }}                 - 字符串截取
 *    {{ $text|str_replace('old', 'new') }}      - 字符串替换
 * 
 * 链式修饰符（从左到右依次应用）：
 *    {{ $html|strip_tags|trim|upper }}
 *    {{ $text|escape|nl2br }}
 *    {{ $price|number_format(2)|trim }}
 * 
 * ============================================================================
 * 特殊变量（全局变量快捷访问）
 * ============================================================================
 * 
 * 1. $request - HTTP请求变量（POST 优先于 GET）
 *    {{ $request.id }}           → $_POST['id'] ?? $_GET['id']
 *    {{ $request.name }}         → $_POST['name'] ?? $_GET['name']
 *    {{ $request.action }}       → $_POST['action'] ?? $_GET['action']
 * 
 * 2. $session - 会话变量
 *    {{ $session.user_id }}      → $_SESSION['user_id']
 *    {{ $session.username }}     → $_SESSION['username']
 * 
 * 3. $server - 服务器变量
 *    {{ $server.REQUEST_METHOD }} → $_SERVER['REQUEST_METHOD']
 *    {{ $server.HTTP_HOST }}      → $_SERVER['HTTP_HOST']
 * 
 * 4. $cookie - Cookie变量
 *    {{ $cookie.token }}         → $_COOKIE['token']
 * 
 * 特殊变量 + 修饰符：
 *    {{ $server.REQUEST_METHOD|lower }}
 *    {{ $session.username|escape|upper }}
 *    {{ $request.nonexist|default('未设置') }}
 * 
 * ⚠️ 安全提示：
 *    配置和环境变量已禁用（安全考虑）
 *    如需使用配置，请在 Controller 中处理后传递给模板
 *    示例：$view->assign('site_name', Config::get('app.name'));
 * 
 * ============================================================================
 * 完整示例
 * ============================================================================
 * 
 * <!DOCTYPE html>
 * <html>
 * <head>
 *     <title>{{ $title|escape }}</title>
 * </head>
 * <body>
 *     {{* 页面头部 *}}
 *     {{ include file="header.tpl" }}
 *     
 *     <h1>欢迎, {{ $session.username|default('游客') }}</h1>
 *     
 *     {{ if $items }}
 *         <ul>
 *         {{ foreach $items as $item }}
 *             <li>{{ $item.title|escape }}</li>
 *         {{ endforeach }}
 *         </ul>
 *     {{ else }}
 *         <p>暂无数据</p>
 *     {{ endif }}
 *     
 *     <p>当前请求: {{ $server.REQUEST_METHOD }} {{ $request.id|default(0) }}</p>
 *     
 *     {{ include file="footer.tpl" }}
 * </body>
 * </html>
 * 
 * ============================================================================
 * @package    OmniPHP\View
 * @author     OmniPHP
 * @copyright  2026
 * @version    1.0.0
 * ============================================================================
 */

namespace OmniPHP\View;

class ViewCompiler
{
    protected string $leftDelimiter;
    protected string $rightDelimiter;
    protected string $templateDir;
    protected string $compileDir;
    protected string $compilePwd;
    protected string $pluginDir;
    protected array $usedPlugins = []; // 跟踪使用的插件
    
    public function __construct(
        string $templateDir,
        string $compileDir,
        string $compilePwd,
        string $pluginDir = '',
        string $left = '{{',
        string $right = '}}'
    ) {
        $this->templateDir = $templateDir;
        $this->compileDir = $compileDir;
        $this->compilePwd = $compilePwd;
        $this->pluginDir = $pluginDir;
        $this->leftDelimiter = preg_quote($left, '/');
        $this->rightDelimiter = preg_quote($right, '/');
    }
    
    /**
     * 编译模板文件
     */
    public function compile(string $tplFile, string $compileFile): bool
    {
        if (!file_exists($tplFile)) {
            return false;
        }
        
        // 重置插件跟踪
        $this->usedPlugins = [];
        
        $content = file_get_contents($tplFile);
        $compiled = $this->compileContent($content);
        
        // 在编译内容前添加插件加载代码
        if (!empty($this->usedPlugins) && !empty($this->pluginDir)) {
            $pluginLoads = $this->generatePluginLoads();
            $compiled = $pluginLoads . $compiled;
        }
        
        // 确保目录存在
        $dir = dirname($compileFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($compileFile, $compiled) !== false;
    }
    
    /**
     * 生成插件加载代码
     */
    protected function generatePluginLoads(): string
    {
        $code = "<?php\n";
        $code .= "// 自动加载使用的插件\n";
        
        foreach ($this->usedPlugins as $pluginKey => $type) {
            $code .= "if (!function_exists('tpl_{$type}_{$pluginKey}')) {\n";
            $code .= "    @include_once '{$this->pluginDir}/{$type}.{$pluginKey}.php';\n";
            $code .= "}\n";
        }
        
        $code .= "?>\n";
        
        return $code;
    }
    
    /**
     * 编译模板内容
     */
    protected function compileContent(string $content): string
    {
        // 提高 PCRE 回溯限制（大模板需要更高的限制）
        $prevLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', 10000000);
        
        // 1. 先编译注释（避免注释内容被编译）
        $content = $this->compileComment($content);
        
        // 2. 编译 Block 插件（必须在 if 之前，因为块可能包含条件）
        $content = $this->compileBlockPlugin($content);
        
        // 3. 编译 if 语句  
        $content = $this->compileIf($content);
        
        // 4. 编译 foreach 循环
        $content = $this->compileForeach($content);
        
        // 5. 编译 for 循环
        $content = $this->compileFor($content);
        
        // 5.5 编译 assign 赋值
        $content = $this->compileAssign($content);
        
        // 6. 编译 include
        $content = $this->compileInclude($content);
        
        // 7. 编译 Function 插件（在变量之前，避免冲突）
        $content = $this->compileFunctionPlugin($content);
        
        // 8. 最后编译变量（避免干扰其他标签）
        $content = $this->compileVariable($content);
        
        return $content;
    }
    
    /**
     * 编译 Block 插件标签
     * 格式: {{ blockname attr1="value" }}...{{ /blockname }}
     * 支持: {{ blockname }}...{{ blockelse }}...{{ /blockname }}
     */
    protected function compileBlockPlugin(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // 先处理带 blockelse 的块标签
        $blockElsePattern = "/{$ld}\s*([a-zA-Z_][a-zA-Z0-9_]*)([^{$rd}]*){$rd}(.*?){$ld}\s*blockelse\s*{$rd}(.*?){$ld}\s*\/\\1\s*{$rd}/s";
        
        $content = preg_replace_callback($blockElsePattern, function($matches) {
            $blockName = $matches[1];
            $attrString = trim($matches[2]);
            $loopContent = $matches[3];
            $elseContent = $matches[4];
            
            // 跳过已知的内置标签
            $builtinTags = ['if', 'elseif', 'else', 'foreach', 'for', 'include', 'assign'];
            if (in_array($blockName, $builtinTags)) {
                return $matches[0];
            }
            
            // 记录使用的插件
            $this->usedPlugins[$blockName] = 'block';
            
            // 解析属性
            $parser = new AttributeParser();
            $parser->parse($blockName . ' ' . $attrString);
            
            // 获取循环变量名
            $key = $parser->get('key', 'k');
            $item = $parser->get('item', 'v');
            
            // 生成属性数组代码
            $attrArray = $parser->toPhpArray();
            $attrArray = preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_.]*)/', function($m) {
                return $this->parseVariable($m[1]);
            }, $attrArray);
            
            // 生成唯一的块ID
            static $blockElseId = 0;
            $blockElseId++;
            
            // 生成块代码（带 else）
            $code = "<?php\n";
            $code .= "\$block_data_{$blockElseId} = tpl_block_{$blockName}(\$this, {$attrArray});\n";
            $code .= "if (is_array(\$block_data_{$blockElseId}) && !empty(\$block_data_{$blockElseId})) {\n";
            $code .= "    foreach (\$block_data_{$blockElseId} as \${$key} => \${$item}) {\n";
            $code .= "?>";
            $code .= $loopContent;
            $code .= "<?php\n";
            $code .= "    }\n";
            $code .= "} else {\n";
            $code .= "?>";
            $code .= $elseContent;
            $code .= "<?php\n";
            $code .= "}\n";
            $code .= "?>";
            
            return $code;
        }, $content) ?? $content;
        
        // 然后处理普通块标签（无 blockelse）
        $pattern = "/{$ld}\s*([a-zA-Z_][a-zA-Z0-9_]*)([^{$rd}]*){$rd}(.*?){$ld}\s*\/\\1\s*{$rd}/s";
        
        $content = preg_replace_callback($pattern, function($matches) {
            $blockName = $matches[1];
            $attrString = trim($matches[2]);
            $blockContent = $matches[3];
            
            // 跳过已知的内置标签
            $builtinTags = ['if', 'foreach', 'for', 'include'];
            if (in_array($blockName, $builtinTags)) {
                return $matches[0];
            }
            
            // 记录使用的插件
            $this->usedPlugins[$blockName] = 'block';
            
            // 解析属性
            $parser = new AttributeParser();
            $parser->parse($blockName . ' ' . $attrString);
            
            // 获取循环变量名
            $key = $parser->get('key', 'k');
            $item = $parser->get('item', 'v');
            
            // 生成属性数组代码
            $attrArray = $parser->toPhpArray();
            $attrArray = preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_.]*)/', function($m) {
                return $this->parseVariable($m[1]);
            }, $attrArray);
            
            // 生成唯一的块ID
            static $blockId = 0;
            $blockId++;
            
            // 生成块代码（无 else）
            $code = "<?php\n";
            $code .= "\$block_data_{$blockId} = tpl_block_{$blockName}(\$this, {$attrArray});\n";
            $code .= "if (is_array(\$block_data_{$blockId})) {\n";
            $code .= "    foreach (\$block_data_{$blockId} as \${$key} => \${$item}) {\n";
            $code .= "?>";
            $code .= $blockContent;
            $code .= "<?php\n";
            $code .= "    }\n";
            $code .= "}\n";
            $code .= "?>";
            
            return $code;
        }, $content) ?? $content;
        
        return $content;
    }
    
    /**
     * 编译 Function 插件标签
     * 格式: {{ #plugin_name attr1="value" attr2=$var }}
     */
    protected function compileFunctionPlugin(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // 匹配 #plugin_name 开头的标签
        $pattern = "/{$ld}\s*#([a-zA-Z_][a-zA-Z0-9_]*)([^{$rd}]*){$rd}/";
        
        $content = preg_replace_callback($pattern, function($matches) {
            $pluginName = $matches[1];
            $attrString = trim($matches[2]);
            
            // 记录使用的插件
            $this->usedPlugins[$pluginName] = 'function';
            
            // 解析属性
            $parser = new AttributeParser();
            $parser->parse($pluginName . ' ' . $attrString);
            
            // 生成属性数组代码
            $attrArray = $parser->toPhpArray();
            
            // 处理属性值中的特殊变量
            $attrArray = preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_.]*)/', function($m) {
                return $this->parseVariable($m[1]);
            }, $attrArray);
            
            // 生成函数调用代码
            return "<?php echo tpl_function_{$pluginName}(\$this, {$attrArray}); ?>";
        }, $content);
        
        return $content;
    }
    
    /**
     * 编译变量（支持链式修饰符、特殊变量和函数调用）
     */
    protected function compileVariable(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // 先匹配函数调用输出: {{ function_name(...) }}
        $funcPattern = "/{$ld}\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^{$rd}]*)\)\s*{$rd}/";
        $content = preg_replace_callback($funcPattern, function($matches) {
            $funcName = $matches[1];
            $args = $matches[2];
            
            // 解析函数参数（处理特殊变量）
            $parsedArgs = $this->parseFunctionArgs($args);
            
            return '<?php echo ' . $funcName . '(' . $parsedArgs . '); ?>';
        }, $content);
        
        // 然后匹配变量: {{ $var }} 或 {{ $var|modifier1|modifier2|... }}
        // 支持 # 开头的自定义修饰符，支持括号语法 |default('x') 和冒号语法 |default:'x'
        $varPattern = "/{$ld}\s*\\$([a-zA-Z_][a-zA-Z0-9_]*(?:[.\\[\\]][^\\|{$rd}]+)*)((?:\\s*\\|\\s*#?[a-zA-Z_][a-zA-Z0-9_]*(?:(?:\\([^)]*\\))|(?::[^|{$rd}]*))?)*)\s*{$rd}/";
        
        $content = preg_replace_callback($varPattern, function($matches) {
            $varName = $matches[1];  // 变量名，如: name, array[0], obj->prop, request.id
            $modifiers = $matches[2]; // 修饰符链，如: |escape|nl2br|upper
            
            // 解析变量（处理特殊变量）
            $phpVar = $this->parseVariable($varName);
            
            // 如果没有修饰符，默认转义输出（XSS 防护）
            if (empty(trim($modifiers))) {
                return '<?php echo htmlspecialchars(' . $phpVar . ', ENT_QUOTES, \'UTF-8\'); ?>';
            }
            
            // 解析修饰符链
            $result = $this->applyModifiers($phpVar, $modifiers);
            
            return '<?php echo ' . $result . '; ?>';
        }, $content);
        
        return $content;
    }
    
    /**
     * 解析变量（处理特殊变量映射）
     */
    protected function parseVariable(string $varName): string
    {
        // 清理变量名中的空格
        $varName = trim($varName);
        
        // 检查是否包含点号（但忽略括号内的点号，如 $row[$cfield . '_badge']）
        $hasTopLevelDot = false;
        $bracketDepth = 0;
        for ($i = 0; $i < strlen($varName); $i++) {
            $ch = $varName[$i];
            if ($ch === '[') $bracketDepth++;
            elseif ($ch === ']') $bracketDepth--;
            elseif ($ch === '.' && $bracketDepth === 0) {
                $hasTopLevelDot = true;
                break;
            }
        }
        
        if (!$hasTopLevelDot) {
            // 普通变量，支持数组和对象访问（保持原样）
            return '$' . $varName;
        }
        
        // 按顶层点号分割（忽略括号内的点号）
        $parts = $this->splitTopLevelDot($varName, 2);
        $prefix = trim($parts[0]);
        $path = trim($parts[1] ?? '');
        
        // 检查是否是特殊变量前缀
        $specialPrefixes = ['request', 'session', 'server', 'cookie', '_block'];
        
        if (!in_array($prefix, $specialPrefixes)) {
            // 不是特殊变量，转换点号为数组访问
            // 例如: $v.title → $v['title']
            //      $user.profile.name → $user['profile']['name']
            $result = '$' . $prefix;
            $pathParts = explode('.', $path);
            foreach ($pathParts as $part) {
                $part = trim($part);  // 去除空格
                if (!empty($part)) {
                    $result .= "['{$part}']";
                }
            }
            return $result;
        }
        
        // 处理特殊变量前缀
        $result = match($prefix) {
            // $_GET 和 $_POST (优先 POST)
            'request' => $this->buildRequestVar($path),
            
            // $_SESSION
            'session' => $this->buildSessionVar($path),
            
            // $_SERVER
            'server' => $this->buildServerVar($path),
            
            // $_COOKIE
            'cookie' => $this->buildCookieVar($path),
            
            // $_block (块索引变量)
            '_block' => $this->buildBlockVar($path),
            
            // 默认（不应该到这里）
            default => '$' . $varName
        };
        
        return $result;
    }
    
    /**
     * 按顶层点号分割字符串（忽略括号内的点号）
     */
    protected function splitTopLevelDot(string $str, int $limit = -1): array
    {
        $parts = [];
        $current = '';
        $bracketDepth = 0;
        $count = 1;
        
        for ($i = 0; $i < strlen($str); $i++) {
            $ch = $str[$i];
            if ($ch === '[') $bracketDepth++;
            elseif ($ch === ']') $bracketDepth--;
            elseif ($ch === '.' && $bracketDepth === 0 && ($limit < 0 || $count < $limit)) {
                $parts[] = $current;
                $current = '';
                $count++;
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;
        
        return $parts;
    }
    
    /**
     * 构建 request 变量（$_POST 优先，然后 $_GET）
     */
    protected function buildRequestVar(string $path): string
    {
        $keys = $this->parsePath($path);
        $accessor = $this->buildArrayAccessor($keys);
        return "(\$_POST{$accessor} ?? \$_GET{$accessor} ?? null)";
    }
    
    /**
     * 构建 session 变量
     */
    protected function buildSessionVar(string $path): string
    {
        $keys = $this->parsePath($path);
        $accessor = $this->buildArrayAccessor($keys);
        return "(\$_SESSION{$accessor} ?? null)";
    }
    
    /**
     * 构建 server 变量
     */
    protected function buildServerVar(string $path): string
    {
        $keys = $this->parsePath($path);
        $accessor = $this->buildArrayAccessor($keys);
        return "(\$_SERVER{$accessor} ?? null)";
    }
    
    /**
     * 构建 cookie 变量
     */
    protected function buildCookieVar(string $path): string
    {
        $keys = $this->parsePath($path);
        $accessor = $this->buildArrayAccessor($keys);
        return "(\$_COOKIE{$accessor} ?? null)";
    }
    
    /**
     * 构建 _block 变量（块索引）
     */
    protected function buildBlockVar(string $path): string
    {
        $keys = $this->parsePath($path);
        $accessor = $this->buildArrayAccessor($keys);
        return "(\$_block{$accessor} ?? null)";
    }
    
    /**
     * 解析路径（支持点号和数组语法）
     */
    protected function parsePath(string $path): array
    {
        // 替换点号为临时标记，保护数组语法中的点号
        // 示例: user.profile.name → ['user', 'profile', 'name']
        //      user.items[0].name → ['user', 'items[0]', 'name']
        
        $keys = [];
        $current = '';
        $inBracket = false;
        
        for ($i = 0; $i < strlen($path); $i++) {
            $char = $path[$i];
            
            if ($char === '[') {
                $inBracket = true;
                $current .= $char;
            } elseif ($char === ']') {
                $inBracket = false;
                $current .= $char;
            } elseif ($char === '.' && !$inBracket) {
                if ($current !== '') {
                    $keys[] = trim($current);  // 去除空格
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }
        
        if ($current !== '') {
            $keys[] = trim($current);  // 去除空格
        }
        
        return $keys;
    }
    
    /**
     * 构建数组访问器
     */
    protected function buildArrayAccessor(array $keys): string
    {
        $accessor = '';
        
        foreach ($keys as $key) {
            // 检查是否已经包含数组语法
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(\[.+\])$/', $key, $m)) {
                // 已有数组语法: items[0]
                $accessor .= "['{$m[1]}']{$m[2]}";
            } else {
                // 普通键
                $accessor .= "['{$key}']";
            }
        }
        
        return $accessor;
    }
    
    /**
     * 应用修饰符链
     */
    protected function applyModifiers(string $value, string $modifierChain): string
    {
        // 按 | 分割修饰符
        $modifiers = array_filter(array_map('trim', explode('|', $modifierChain)));
        
        $result = $value;
        
        foreach ($modifiers as $modifier) {
            // 检查是否有参数: modifier(arg1, arg2) 或 #modifier(arg1, arg2)
            if (preg_match('/^(#?[a-zA-Z_][a-zA-Z0-9_]*)\s*\((.+)\)$/', $modifier, $m)) {
                $modName = $m[1];
                $args = $m[2];
                // 处理参数中的变量（如 $fconfig.default → $fconfig['default']）
                $args = preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_.]*)/', function($vm) {
                    return $this->parseVariable($vm[1]);
                }, $args);
                $result = $this->compileModifierWithArgs($result, $modName, $args);
            }
            // Smarty 冒号语法: modifier:arg (如 default:'value' 或 default:$var)
            elseif (preg_match('/^(#?[a-zA-Z_][a-zA-Z0-9_]*):(.+)$/', $modifier, $m)) {
                $modName = $m[1];
                $args = $m[2];
                // 处理参数中的变量
                $args = preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_.]*)/', function($vm) {
                    return $this->parseVariable($vm[1]);
                }, $args);
                $result = $this->compileModifierWithArgs($result, $modName, $args);
            } else {
                $result = $this->compileModifier($result, $modifier);
            }
        }
        
        return $result;
    }
    
    /**
     * 编译单个修饰符（无参数，支持自定义插件修饰符）
     */
    protected function compileModifier(string $value, string $modifier): string
    {
        // 检查是否是自定义修饰符（# 开头）
        if (str_starts_with($modifier, '#')) {
            $pluginName = substr($modifier, 1);
            $this->usedPlugins[$pluginName] = 'modifier';
            return "tpl_modifier_{$pluginName}({$value})";
        }
        
        // 内置修饰符
        return match($modifier) {
            'raw' => $value, // 不转义（原始输出）
            'escape' => "htmlspecialchars({$value}, ENT_QUOTES, 'UTF-8')",
            'nl2br' => "nl2br({$value})",
            'upper' => "strtoupper({$value})",
            'lower' => "strtolower({$value})",
            'trim' => "trim({$value})",
            'ucfirst' => "ucfirst({$value})",
            'ucwords' => "ucwords({$value})",
            'strip_tags' => "strip_tags({$value})",
            'urlencode' => "urlencode({$value})",
            'json' => "json_encode({$value})",
            'json_encode' => "json_encode({$value})",
            'default' => $value, // 默认值需要在带参数版本中处理
            default => $value // 未知修饰符，保持不变
        };
    }
    
    /**
     * 编译带参数的修饰符（支持自定义插件修饰符）
     */
    protected function compileModifierWithArgs(string $value, string $modifier, string $args): string
    {
        // 检查是否是自定义修饰符（# 开头）
        if (str_starts_with($modifier, '#')) {
            $pluginName = substr($modifier, 1);
            $this->usedPlugins[$pluginName] = 'modifier';
            return "tpl_modifier_{$pluginName}({$value}, {$args})";
        }
        
        // 内置修饰符
        return match($modifier) {
            'default' => "({$value} ?? {$args})",
            'number_format' => "number_format({$value}, {$args})",
            'date_format' => "date({$args}, strtotime({$value}))",
            'substr' => "substr({$value}, {$args})",
            'str_replace' => "str_replace({$args}, {$value})",
            default => $value // 未知修饰符，保持不变
        };
    }
    
    /**
     * 编译 if 语句（支持函数调用）
     */
    protected function compileIf(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // {{ if $condition }}
        $content = preg_replace_callback("/{$ld}\s*if\s+(.+?)\s*{$rd}/", function($m) {
            $condition = $this->parseExpression($m[1]);
            return '<?php if (' . $condition . '): ?>';
        }, $content) ?? $content;
        
        // {{ elseif $condition }}
        $content = preg_replace_callback("/{$ld}\s*elseif\s+(.+?)\s*{$rd}/", function($m) {
            $condition = $this->parseExpression($m[1]);
            return '<?php elseif (' . $condition . '): ?>';
        }, $content) ?? $content;
        
        // {{ else }}
        $content = preg_replace("/{$ld}\s*else\s*{$rd}/", '<?php else: ?>', $content) ?? $content;
        
        // {{ endif }} 或 {{ /if }}
        $content = preg_replace("/{$ld}\s*(?:endif|\/if)\s*{$rd}/", '<?php endif; ?>', $content) ?? $content;
        
        return $content;
    }
    
    /**
     * 解析表达式（处理函数调用和特殊变量）
     */
    protected function parseExpression(string $expression): string
    {
        $expression = trim($expression);

        // 先处理表达式中的带修饰符变量：$var|default:...|...
        // 例如：$fconfig.options|default:[]
        $expression = preg_replace_callback(
            '/(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]+\]|(?:\.[a-zA-Z_][a-zA-Z0-9_]*))*)(\s*(?:\|\s*#?[a-zA-Z_][a-zA-Z0-9_]*(?:\([^)]*\)|:[^|\s\)]+)?)+)/',
            function($matches) {
                $rawVar = $matches[1];
                $modifierChain = $matches[2] ?? '';

                if ($modifierChain === '') {
                    return $matches[0];
                }

                $varName = ltrim($rawVar, '$');
                $phpVar = $this->parseVariable($varName);
                return $this->applyModifiers($phpVar, $modifierChain);
            },
            $expression
        ) ?? $expression;
        
        // 递归处理表达式中的所有部分
        // 匹配函数调用: function_name(...) 或特殊变量 $request.id
        $pattern = '/([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)|(\$[a-zA-Z_][a-zA-Z0-9_.]*)/';
        
        $result = preg_replace_callback($pattern, function($matches) {
            // 匹配到函数调用
            if (!empty($matches[1])) {
                $funcName = $matches[1];
                $args = $matches[2];
                
                // 递归处理参数
                $parsedArgs = $this->parseFunctionArgs($args);
                
                return $funcName . '(' . $parsedArgs . ')';
            }
            // 匹配到变量
            else if (!empty($matches[3])) {
                $varName = substr($matches[3], 1); // 移除 $
                return $this->parseVariable($varName);
            }
            
            return $matches[0];
        }, $expression);
        
        return $result;
    }
    
    /**
     * 解析函数参数
     */
    protected function parseFunctionArgs(string $args): string
    {
        if (empty(trim($args))) {
            return '';
        }
        
        // 简单处理：替换参数中的特殊变量
        // 这里使用简单的替换，对于复杂的参数解析，需要更复杂的词法分析
        return preg_replace_callback('/\$([a-zA-Z_][a-zA-Z0-9_.]*)/', function($m) {
            return $this->parseVariable($m[1]);
        }, $args);
    }
    
    /**
     * 编译 foreach 循环（支持 name 属性、块索引和 foreachelse）
     */
    protected function compileForeach(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // 先处理 foreach...else...endforeach 结构
        $foreachElsePattern = "/{$ld}\s*foreach\s+(.+?)\s+as\s+(.+?)(?:\s+name=[\"']([a-zA-Z_][a-zA-Z0-9_]*)[\"'])?\s*{$rd}(.*?){$ld}\s*foreachelse\s*{$rd}(.*?){$ld}\s*endforeach\s*{$rd}/s";
        
        $content = preg_replace_callback($foreachElsePattern, function($m) {
            $from = $this->parseExpression($m[1]);
            $as = $this->parseExpression($m[2]);
            $name = $m[3] ?? '';
            $loopContent = $m[4];
            $elseContent = $m[5];
            
            static $foreachId = 0;
            $foreachId++;
            
            $code = "<?php\n";
            $code .= "\$_foreach_data_{$foreachId} = {$from};\n";
            $code .= "if (!empty(\$_foreach_data_{$foreachId})) {\n";
            
            // 如果指定了 name，初始化块索引
            if (!empty($name)) {
                $code .= "    \$_block['{$name}']['index'] = 0;\n";
            }
            
            $code .= "    foreach (\$_foreach_data_{$foreachId} as {$as}) {\n";
            
            // 如果指定了 name，增加索引
            if (!empty($name)) {
                $code .= "        \$_block['{$name}']['index']++;\n";
            }
            
            $code .= "?>";
            $code .= $loopContent;
            $code .= "<?php\n";
            $code .= "    }\n";
            $code .= "} else {\n";
            $code .= "?>";
            $code .= $elseContent;
            $code .= "<?php\n";
            $code .= "}\n";
            $code .= "?>";
            
            return $code;
        }, $content) ?? $content;
        
        // 然后处理普通 foreach
        $content = preg_replace_callback("/{$ld}\s*foreach\s+(.+?)\s+as\s+(.+?)(?:\s+name=[\"']([a-zA-Z_][a-zA-Z0-9_]*)[\"'])?\s*{$rd}/", function($m) {
            $from = $this->parseExpression($m[1]);
            $as = $this->parseExpression($m[2]);
            $name = $m[3] ?? '';
            
            $code = '<?php ';
            
            // 如果指定了 name，初始化块索引
            if (!empty($name)) {
                $code .= "\$_block['{$name}']['index'] = 0; ";
            }
            
            $code .= 'foreach (' . $from . ' as ' . $as . '): ';
            
            // 如果指定了 name，增加索引
            if (!empty($name)) {
                $code .= "\$_block['{$name}']['index']++; ";
            }
            
            $code .= '?>';
            
            return $code;
        }, $content);
        
        // {{ endforeach }} 或 {{ /foreach }}
        $content = preg_replace("/{$ld}\s*(?:endforeach|\/foreach)\s*{$rd}/", '<?php endforeach; ?>', $content) ?? $content;
        
        return $content;
    }
    
    /**
     * 编译 for 循环
     */
    protected function compileFor(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // {{ for $i=0; $i<10; $i++ }}
        $content = preg_replace_callback("/{$ld}\s*for\s+(.+?)\s*{$rd}/", function($m) {
            return '<?php for (' . $m[1] . '): ?>';
        }, $content);
        
        // {{ endfor }} 或 {{ /for }}
        $content = preg_replace("/{$ld}\s*(?:endfor|\/for)\s*{$rd}/", '<?php endfor; ?>', $content) ?? $content;
        
        return $content;
    }
    
    /**
     * 编译 assign 赋值标签
     * 格式: {{ assign $var = expression }}
     */
    protected function compileAssign(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // {{ assign $var = expression }}
        $content = preg_replace_callback("/{$ld}\s*assign\s+\\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+?)\s*{$rd}/", function($m) {
            $varName = $m[1];
            $expression = $this->parseExpression($m[2]);
            return '<?php $' . $varName . ' = ' . $expression . '; ?>';
        }, $content) ?? $content;
        
        return $content;
    }
    
    /**
     * 编译 include
     */
    protected function compileInclude(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // {{ include file="header.tpl" }}
        $content = preg_replace_callback("/{$ld}\s*include\s+file=[\"'](.+?)[\"']\s*{$rd}/", function($m) {
            $fileName = $m[1];
            
            // 自动添加 .tpl 扩展名（如果没有）
            if (!str_ends_with($fileName, '.tpl')) {
                $fileName .= '.tpl';
            }
            
            // 获取被包含文件的编译文件路径
            $includeInfo = $this->getIncludeFileInfo($fileName);
            
            if (!$includeInfo['exists']) {
                return "<?php /* Error: Template file '{$fileName}' not found! */ ?>";
            }
            
            // 返回包含编译后的文件
            return "<?php require '{$includeInfo['compile_file']}'; ?>";
        }, $content);
        
        return $content;
    }
    
    /**
     * 获取被包含文件的信息
     */
    protected function getIncludeFileInfo(string $fileName): array
    {
        $tplFile = $this->templateDir . '/' . $fileName;
        
        // 检查模板文件是否存在
        if (!file_exists($tplFile)) {
            return [
                'exists' => false,
                'tpl_file' => $tplFile,
                'compile_file' => ''
            ];
        }
        
        // 生成编译文件名（与 ViewEngine 中的逻辑一致）
        $tplNames = preg_split("/[\/\\\\]/", $fileName);
        $comFile = str_replace('.tpl', '_' . $this->compilePwd . '.php', implode('__', $tplNames));
        $comFile = str_replace('..', '__', $comFile);
        $compileFile = $this->compileDir . '/' . $comFile;
        
        // 检查是否需要编译被包含的文件
        if (!file_exists($compileFile) || filemtime($compileFile) < filemtime($tplFile)) {
            // 递归编译被包含的文件
            $this->compile($tplFile, $compileFile);
        }
        
        return [
            'exists' => true,
            'tpl_file' => $tplFile,
            'compile_file' => $compileFile
        ];
    }
    
    /**
     * 编译注释
     */
    protected function compileComment(string $content): string
    {
        $ld = $this->leftDelimiter;
        $rd = $this->rightDelimiter;
        
        // {{* comment *}}
        $content = preg_replace("/{$ld}\\*(.+?)\\*{$rd}/s", '', $content);
        
        return $content;
    }
}
