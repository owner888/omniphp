<?php
/**
 * 属性解析器
 * 
 * 解析模板标签的属性字符串，支持多种引号格式：
 * - 单引号: attr='value'
 * - 双引号: attr="value"
 * - 无引号: attr=value
 * - 变量: attr=$variable
 * 
 * 示例：
 *   输入: myblock name="list" type='hot' limit=10 sort="desc" active=$status
 *   输出: [
 *     'tagname' => 'myblock',
 *     'name' => 'list',
 *     'type' => 'hot',
 *     'limit' => '10',
 *     'sort' => 'desc',
 *     'active' => '$status'
 *   ]
 */

namespace OmniPHP\View;

class AttributeParser
{
    protected array $attributes = [];
    protected string $tagName = '';
    
    /**
     * 解析属性字符串
     */
    public function parse(string $source): self
    {
        $this->attributes = [];
        $this->tagName = '';
        
        $source = trim(preg_replace('/\s+/', ' ', $source)); // 标准化空格
        
        if (empty($source)) {
            return $this;
        }
        
        // 提取标签名称（第一个空格前的内容）
        $spacePos = strpos($source, ' ');
        if ($spacePos === false) {
            // 只有标签名，没有属性
            $this->tagName = $source;
            $this->attributes['tagname'] = $source;
            return $this;
        }
        
        $this->tagName = substr($source, 0, $spacePos);
        $this->attributes['tagname'] = $this->tagName;
        
        // 解析属性部分
        $attrString = substr($source, $spacePos + 1);
        $this->parseAttributes($attrString);
        
        return $this;
    }
    
    /**
     * 解析属性列表
     */
    protected function parseAttributes(string $attrString): void
    {
        $length = strlen($attrString);
        $i = 0;
        
        while ($i < $length) {
            // 跳过空格
            while ($i < $length && $attrString[$i] === ' ') {
                $i++;
            }
            
            if ($i >= $length) {
                break;
            }
            
            // 读取属性名
            $attrName = '';
            while ($i < $length && $attrString[$i] !== '=' && $attrString[$i] !== ' ') {
                $attrName .= $attrString[$i];
                $i++;
            }
            
            if (empty($attrName)) {
                break;
            }
            
            // 跳过空格
            while ($i < $length && $attrString[$i] === ' ') {
                $i++;
            }
            
            // 检查是否有 =
            if ($i >= $length || $attrString[$i] !== '=') {
                // 没有值的属性（布尔属性）
                $this->attributes[$attrName] = true;
                continue;
            }
            
            $i++; // 跳过 =
            
            // 跳过空格
            while ($i < $length && $attrString[$i] === ' ') {
                $i++;
            }
            
            if ($i >= $length) {
                $this->attributes[$attrName] = '';
                break;
            }
            
            // 读取属性值
            $attrValue = '';
            $quote = $attrString[$i];
            
            // 有引号的值
            if ($quote === '"' || $quote === "'") {
                $i++; // 跳过开始引号
                while ($i < $length) {
                    // 检查转义
                    if ($attrString[$i] === '\\' && $i + 1 < $length && $attrString[$i + 1] === $quote) {
                        $attrValue .= $quote;
                        $i += 2;
                    }
                    // 结束引号
                    else if ($attrString[$i] === $quote) {
                        $i++;
                        break;
                    }
                    // 普通字符
                    else {
                        $attrValue .= $attrString[$i];
                        $i++;
                    }
                }
            }
            // 无引号的值
            else {
                while ($i < $length && $attrString[$i] !== ' ') {
                    $attrValue .= $attrString[$i];
                    $i++;
                }
            }
            
            $this->attributes[$attrName] = $attrValue;
        }
    }
    
    /**
     * 获取标签名称
     */
    public function getTagName(): string
    {
        return $this->tagName;
    }
    
    /**
     * 获取所有属性
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
    
    /**
     * 获取单个属性
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
    
    /**
     * 检查属性是否存在
     */
    public function has(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
    
    /**
     * 获取属性数量（不包含 tagname）
     */
    public function count(): int
    {
        return count($this->attributes) - 1; // 减去 tagname
    }
    
    /**
     * 转换为 PHP 数组代码
     * 用于在编译时生成属性数组
     */
    public function toPhpArray(): string
    {
        $items = [];
        
        foreach ($this->attributes as $key => $value) {
            if ($key === 'tagname') {
                continue;
            }
            
            // 检查是否是变量
            if (is_string($value) && str_starts_with($value, '$')) {
                // 变量需要特殊处理
                $varName = substr($value, 1);
                $items[] = "'{$key}' => \${$varName}";
            } else if (is_bool($value)) {
                $items[] = "'{$key}' => " . ($value ? 'true' : 'false');
            } else if (is_numeric($value)) {
                $items[] = "'{$key}' => {$value}";
            } else {
                // 字符串值，需要转义
                $escapedValue = addslashes($value);
                $items[] = "'{$key}' => '{$escapedValue}'";
            }
        }
        
        return '[' . implode(', ', $items) . ']';
    }
}
