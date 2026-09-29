<?php
/**
 * 高性能文件缓存驱动
 * 
 * 使用二进制存储和hash链表结构，提供更高效的缓存性能
 * 
 * 特性：
 * - 二进制存储格式，高效的空间利用
 * - Hash索引结构，快速查找
 * - 链表管理冲突，支持大量数据
 * - 内存缓存层，减少磁盘I/O
 * - 自动过期清理
 * - 文件锁保护，支持并发访问
 */

namespace OmniPHP\Cache;

use OmniPHP\Logger;

class FileCache
{
    // 缓存文件
    private ?string $cacheFile = null;
    
    // 缓存目录
    private static ?string $cacheDir = null;
    
    // Hash算法掩码 (0x5FFFF = 393215)
    // 总数据量 ≈ maskValue * linkMax / 2
    private int $maskValue = 0x5FFFF;
    
    // 链表最大长度（当单个hash链表太长时，性能将比较差）
    private int $linkMax = 10000;
    
    // 缓存文件最大大小，超过会rebuild收缩(单位MB)
    private int $fileMaxMB = 1024;
    
    // 重建的最小间隔时间
    private int $rebuildTime = 86400;
    
    // 文件防下载编码
    private string $exitCode = '<?php exit(); ?>';
    
    // 文件防下载编码长度
    private int $exitCodeLength = 16;
    
    // 删除元素时是否保留块（预留配置项，暂未实现）
    // 保留则下次再set时，可以使用回这个块，但缺点是可能导致链表增长查询变慢
    // 不保留重复删除和set则可能导致数据量增长，视情况选择
    private bool $reserveDelBlock = true;
    
    // 基本信息长度（元数据结构大小）
    // S(2) + l(4) + l(4) + l(4) + l(4) + l(4) = 22 bytes
    private int $metaLength = 22;
    
    // 文件句柄
    private $cacheFp = null;
    
    // 是否单用户模式（这个模式时，进行写操作不锁定文件）
    public bool $isSingle = false;
    
    // 内存缓存层（减少磁盘I/O）
    private array $memoryCache = [];
    
    // 内存缓存大小限制
    private int $memoryCacheLimit = 1000;
    
    // 单例实例
    private static ?self $instance = null;
    
    /**
     * 获取单例实例
     */
    private static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化
     */
    public static function init(): void
    {
        if (self::$cacheDir !== null) {
            return;
        }
        
        self::$cacheDir = defined('RUNTIME_PATH') 
            ? RUNTIME_PATH . '/cache/data' 
            : sys_get_temp_dir() . '/omniphp_cache';
        
        // 创建缓存目录
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
        
        Logger::info("文件缓存初始化完成", ['cache_dir' => self::$cacheDir]);
    }
    
    /**
     * 构造函数
     */
    public function __construct(?string $cacheFile = null, bool $isSingle = false)
    {
        self::init();
        
        $this->isSingle = $isSingle;
        $this->cacheFile = $cacheFile ?? self::$cacheDir . '/filecache_data.php';
        
        if (!file_exists($this->cacheFile)) {
            $this->create();
        } else if (filesize($this->cacheFile) > $this->fileMaxMB * 1024 * 1024) {
            $this->rebuild();
        }
    }
    
    /**
     * 析构函数
     */
    public function __destruct()
    {
        if ($this->cacheFp) {
            @fclose($this->cacheFp);
            $this->cacheFp = null;
        }
    }
    
    /**
     * 打开文件
     */
    private function open()
    {
        if ($this->cacheFp) {
            return $this->cacheFp;
        }
        
        if (!file_exists($this->cacheFile)) {
            return $this->create();
        }
        
        $this->cacheFp = @fopen($this->cacheFile, 'rb+');
        
        if (!$this->cacheFp) {
            throw new \Exception("Cache file does not exist or no permission!");
        }
        
        return $this->cacheFp;
    }
    
    /**
     * 创建新文件
     */
    private function create()
    {
        $this->cacheFp = fopen($this->cacheFile, 'wb+');
        @chmod($this->cacheFile, 0664);
        
        // 创建文件时总是加锁
        flock($this->cacheFp, LOCK_EX);
        
        // 写入防下载代码
        fwrite($this->cacheFp, $this->exitCode);
        
        // 初始化hash索引表
        for ($i = 0; $i <= $this->maskValue; $i++) {
            fwrite($this->cacheFp, pack("l", 0));
        }
        
        rewind($this->cacheFp);
        flock($this->cacheFp, LOCK_UN);
        
        Logger::info("缓存文件创建成功", ['file' => $this->cacheFile]);
        
        return $this->cacheFp;
    }
    
    /**
     * 根据字符串计算key索引（hash函数）
     */
    private function getIndex(string $key): int
    {
        $l = strlen($key);
        $h = 0x238f13af;
        
        while ($l--) {
            $h += ($h << 5);
            $h ^= ord($key[$l]);
            $h &= 0x7fffffff;
        }
        
        return ($h % $this->maskValue);
    }
    
    /**
     * 获得索引和规范化的key
     */
    private function getIndexSign(string $key): array
    {
        if (strlen($key) > 32) {
            $key = md5($key);
        }
        $keyIndex = $this->getIndex($key);
        return [$keyIndex, $key];
    }
    
    /**
     * 检查数据是否过期
     */
    private function checkData(array $node, $data)
    {
        if ($node['data_len'] == 0 || ($node['exptime'] > 0 && $node['time'] + $node['exptime'] < time())) {
            return false;
        }
        return $data;
    }
    
    /**
     * 获取值（静态方法）
     */
    public static function get(string $key): mixed
    {
        return self::getInstance()->getValue($key);
    }
    
    /**
     * 获取值（实例方法，支持返回节点）
     */
    public function getValue(string $key, bool $needCurNode = false): mixed
    {
        // 检查内存缓存
        if (isset($this->memoryCache[$key])) {
            $cached = $this->memoryCache[$key];
            if ($cached['expire'] == 0 || $cached['expire'] > time()) {
                return $needCurNode ? false : $cached['value'];
            } else {
                // 已过期，从内存缓存中删除
                unset($this->memoryCache[$key]);
            }
        }
        
        $this->open();
        
        if ($key == '') {
            return false;
        }
        
        [$keyIndex, $keySign] = $this->getIndexSign($key);
        
        fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
        $darr = unpack('l1h', fread($this->cacheFp, 4));
        $headPos = $darr['h'];
        
        if ($headPos == 0) {
            return false;
        }
        
        $nPos = $headPos;
        $n = 0;
        
        do {
            fseek($this->cacheFp, $nPos);
            $curNode = [];
            $infoDat = fread($this->cacheFp, $this->metaLength);
            
            if (strlen($infoDat) != $this->metaLength) {
                return false;
            }
            
            $curNode = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $infoDat);
            $curNode['pos'] = $nPos;
            $curNode['curkey'] = false;
            
            if ($curNode['key_len'] == 0) {
                $nPos = $curNode['next'];
                continue;
            }
            
            $_key = fread($this->cacheFp, $curNode['key_len']);
            
            if ($_key == $keySign) {
                if ($curNode['data_len'] > 0) {
                    $data = unserialize(fread($this->cacheFp, $curNode['data_len']));
                } else {
                    $data = false;
                }
                
                $curNode['curkey'] = true;
                
                if ($needCurNode) {
                    return $curNode;
                }
                
                $result = $this->checkData($curNode, $data);
                
                // 存入内存缓存
                if ($result !== false) {
                    $this->addToMemoryCache($key, $result, $curNode['exptime'] > 0 ? $curNode['time'] + $curNode['exptime'] : 0);
                }
                
                return $result;
            } else {
                $nPos = $curNode['next'];
            }
            
            $n++;
        } while ($nPos > 0 && $n < $this->linkMax);
        
        return $needCurNode ? $curNode : false;
    }
    
    /**
     * 添加到内存缓存
     */
    private function addToMemoryCache(string $key, $value, int $expire): void
    {
        // 限制内存缓存大小
        if (count($this->memoryCache) >= $this->memoryCacheLimit) {
            // 移除最旧的缓存项
            $oldest = array_key_first($this->memoryCache);
            unset($this->memoryCache[$oldest]);
        }
        
        $this->memoryCache[$key] = [
            'value' => $value,
            'expire' => $expire
        ];
    }
    
    /**
     * 设置值（静态方法）
     */
    public static function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return self::getInstance()->setValue($key, $value, $ttl);
    }
    
    /**
     * 设置值（实例方法）
     */
    public function setValue(string $key, mixed $value, int $exptime = 3600): bool
    {
        if ($key == '') {
            return false;
        }
        
        // 更新内存缓存
        $expire = $exptime > 0 ? time() + $exptime : 0;
        $this->addToMemoryCache($key, $value, $expire);
        
        $this->open();
        
        [$keyIndex, $keySign] = $this->getIndexSign($key);
        
        fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
        $darr = unpack('l1h', fread($this->cacheFp, 4));
        $headPos = $darr['h'];
        
        // 序列化数据
        $valueData = serialize($value);
        
        // 链表为空
        if ($headPos == 0) {
            if (!$this->isSingle) {
                flock($this->cacheFp, LOCK_EX);
            }
            
            // 构造节点数据：key_len, data_len, pre, next, time, exptime
            $saveData = pack('Slllll', strlen($keySign), strlen($valueData), 0, 0, time(), $exptime) 
                      . $keySign . $valueData;
            
            // 保存数据到文件尾部
            fseek($this->cacheFp, 0, SEEK_END);
            $headPos = ftell($this->cacheFp);
            fwrite($this->cacheFp, $saveData);
            
            // 保存链表头位置
            fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
            fwrite($this->cacheFp, pack('l', $headPos));
            
            if (!$this->isSingle) {
                flock($this->cacheFp, LOCK_UN);
            }
        } else {
            $curNode = $this->getValue($key, true);
            
            if (!$this->isSingle) {
                flock($this->cacheFp, LOCK_EX);
            }
            
            // 确保 $curNode 是有效的数组
            if (!is_array($curNode) || !isset($curNode['curkey'])) {
                // 如果获取节点失败，创建新节点作为链表头
                $saveData = pack('Slllll', strlen($keySign), strlen($valueData), 0, 0, time(), $exptime) 
                          . $keySign . $valueData;
                
                fseek($this->cacheFp, 0, SEEK_END);
                $newPos = ftell($this->cacheFp);
                fwrite($this->cacheFp, $saveData);
                
                // 更新头指针
                fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
                fwrite($this->cacheFp, pack('l', $newPos));
            }
            // 不存在相同的key数据，直接在文件末尾写数据
            else if (!$curNode['curkey']) {
                $saveData = pack('Slllll', strlen($keySign), strlen($valueData), $curNode['pos'], 0, time(), $exptime) 
                          . $keySign . $valueData;
                
                // 保存数据到文件尾部
                fseek($this->cacheFp, 0, SEEK_END);
                $newPos = ftell($this->cacheFp);
                fwrite($this->cacheFp, $saveData);
                
                // 改变最后一个节点next指针
                fseek($this->cacheFp, $curNode['pos'] + 10);
                fwrite($this->cacheFp, pack('l', $newPos));
            }
            // 如果新数据比旧数据小或相等，直接在原来位置修改数据
            else if (strlen($valueData) <= $curNode['data_len']) {
                $saveData = pack('Slllll', strlen($keySign), strlen($valueData), $curNode['pre'], $curNode['next'], time(), $exptime) 
                          . $keySign . $valueData;
                
                fseek($this->cacheFp, $curNode['pos']);
                fwrite($this->cacheFp, $saveData);
            }
            // 如果新数据比旧数据大，在文件末尾追加数据
            else {
                $saveData = pack('Slllll', strlen($keySign), strlen($valueData), $curNode['pre'], $curNode['next'], time(), $exptime) 
                          . $keySign . $valueData;
                
                // 保存数据到文件尾部
                fseek($this->cacheFp, 0, SEEK_END);
                $newPos = ftell($this->cacheFp);
                fwrite($this->cacheFp, $saveData);
                
                // 改变前一个节点next指针
                if ($curNode['pre'] > 0) {
                    fseek($this->cacheFp, $curNode['pre'] + 10);
                    fwrite($this->cacheFp, pack('l', $newPos));
                } else {
                    // 更新头指针
                    fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
                    fwrite($this->cacheFp, pack('l', $newPos));
                }
                
                // 改变后一个节点pre指针
                if ($curNode['next'] > 0) {
                    fseek($this->cacheFp, $curNode['next'] + 6);
                    fwrite($this->cacheFp, pack('l', $newPos));
                }
            }
            
            if (!$this->isSingle) {
                flock($this->cacheFp, LOCK_UN);
            }
        }
        
        return true;
    }
    
    /**
     * 删除整个链接(把header设为0)
     * 
     * 注意：这是预留方法，当前版本未使用
     * 可用于清空某个hash索引下的整个链表
     */
    private function delLink(int $keyIndex): void
    {
        fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
        fwrite($this->cacheFp, pack("l", 0));
    }
    
    /**
     * 删除键（静态方法）
     */
    public static function delete(string $key): bool
    {
        return self::getInstance()->deleteValue($key);
    }
    
    /**
     * delete 同名函数（别名）
     */
    public function del(string $key): bool
    {
        return $this->deleteValue($key);
    }
    
    /**
     * 删除键（实例方法）
     */
    public function deleteValue(string $key): bool
    {
        $this->open();
        
        if ($key == '') {
            return false;
        }
        
        // 从内存缓存中删除
        if (isset($this->memoryCache[$key])) {
            unset($this->memoryCache[$key]);
        }
        
        $curNode = $this->getValue($key, true);
        
        // 确保 $curNode 是有效的数组并且找到了key
        if (is_array($curNode) && isset($curNode['curkey']) && $curNode['curkey']) {
            fseek($this->cacheFp, $curNode['pos'] + 2);
            
            if (!$this->isSingle) {
                flock($this->cacheFp, LOCK_EX);
            }
            
            // 只修改 key_len 字段（2字节），标记为删除
            fwrite($this->cacheFp, pack('S', 0));
            
            if (!$this->isSingle) {
                flock($this->cacheFp, LOCK_UN);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查键是否存在
     */
    public static function exists(string $key): bool
    {
        $value = self::get($key);
        return $value !== false && $value !== null;
    }
    
    /**
     * 获取指定key的链表数据（用于调试）
     */
    public function getList(string $key): array
    {
        $this->open();
        
        if ($key == '') {
            return [];
        }
        
        [$keyIndex, $keySign] = $this->getIndexSign($key);
        
        fseek($this->cacheFp, $keyIndex * 4 + $this->exitCodeLength);
        $darr = unpack('l1h', fread($this->cacheFp, 4));
        $headPos = $darr['h'];
        
        if ($headPos == 0) {
            return [];
        }
        
        $nPos = $headPos;
        $n = 0;
        $linkDatas = [];
        
        do {
            fseek($this->cacheFp, $nPos);
            $infoDat = fread($this->cacheFp, $this->metaLength);
            $curNode = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $infoDat);
            $curNode['pos'] = $nPos;
            $curNode['key'] = fread($this->cacheFp, $curNode['key_len']);
            
            if ($curNode['data_len'] > 0) {
                $curNode['data'] = unserialize(fread($this->cacheFp, $curNode['data_len']));
            } else {
                $curNode['data'] = "**mark delete status**";
            }
            
            $linkDatas[] = $curNode;
            $nPos = $curNode['next'];
            $n++;
        } while ($nPos > 0 && $n < $this->linkMax);
        
        return $linkDatas;
    }
    
    /**
     * 获取所有匹配的键（静态方法）
     * 
     * 注意：这个操作在大量缓存时可能很慢
     */
    public static function keys(string $pattern): array
    {
        return self::getInstance()->getKeys($pattern);
    }
    
    /**
     * 获取所有匹配的键（实例方法）
     */
    public function getKeys(string $pattern): array
    {
        $this->open();
        
        $keys = [];
        $regex = str_replace('*', '.*', preg_quote($pattern, '/'));
        
        // 遍历所有hash索引
        for ($i = 0; $i <= $this->maskValue; $i++) {
            fseek($this->cacheFp, $i * 4 + $this->exitCodeLength);
            $darr = unpack('l1h', fread($this->cacheFp, 4));
            $headPos = $darr['h'];
            
            if ($headPos == 0) {
                continue;
            }
            
            $nPos = $headPos;
            $n = 0;
            
            do {
                fseek($this->cacheFp, $nPos);
                $infoDat = fread($this->cacheFp, $this->metaLength);
                
                if (strlen($infoDat) != $this->metaLength) {
                    break;
                }
                
                $curNode = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $infoDat);
                
                if ($curNode['key_len'] > 0) {
                    $nodeKey = fread($this->cacheFp, $curNode['key_len']);
                    
                    // 检查是否过期或已删除
                    if ($curNode['data_len'] > 0 && 
                        ($curNode['exptime'] == 0 || $curNode['time'] + $curNode['exptime'] >= time())) {
                        // 模式匹配
                        if (preg_match('/^' . $regex . '$/', $nodeKey)) {
                            $keys[] = $nodeKey;
                        }
                    }
                }
                
                $nPos = $curNode['next'];
                $n++;
            } while ($nPos > 0 && $n < $this->linkMax);
        }
        
        return $keys;
    }
    
    /**
     * 清理过期缓存（静态方法）
     */
    public static function gc(): int
    {
        return self::getInstance()->garbageCollect();
    }
    
    /**
     * 清理过期缓存（实例方法）
     */
    public function garbageCollect(): int
    {
        $this->open();
        
        $deleted = 0;
        
        // 遍历所有hash索引
        for ($i = 0; $i <= $this->maskValue; $i++) {
            fseek($this->cacheFp, $i * 4 + $this->exitCodeLength);
            $darr = unpack('l1h', fread($this->cacheFp, 4));
            $headPos = $darr['h'];
            
            if ($headPos == 0) {
                continue;
            }
            
            $nPos = $headPos;
            $n = 0;
            
            do {
                fseek($this->cacheFp, $nPos);
                $infoDat = fread($this->cacheFp, $this->metaLength);
                
                if (strlen($infoDat) != $this->metaLength) {
                    break;
                }
                
                $curNode = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $infoDat);
                
                // 检查是否过期
                if ($curNode['data_len'] > 0 && 
                    $curNode['exptime'] > 0 && 
                    $curNode['time'] + $curNode['exptime'] < time()) {
                    // 标记为删除
                    fseek($this->cacheFp, $nPos + 2);
                    
                    if (!$this->isSingle) {
                        flock($this->cacheFp, LOCK_EX);
                    }
                    
                    fwrite($this->cacheFp, pack('l', 0));
                    
                    if (!$this->isSingle) {
                        flock($this->cacheFp, LOCK_UN);
                    }
                    
                    $deleted++;
                }
                
                $nPos = $curNode['next'];
                $n++;
            } while ($nPos > 0 && $n < $this->linkMax);
        }
        
        Logger::info("垃圾回收完成", ['deleted' => $deleted]);
        
        return $deleted;
    }
    
    /**
     * 清空所有缓存（静态方法）
     */
    public static function flush(): bool
    {
        return self::getInstance()->clear();
    }
    
    /**
     * 清空所有缓存（实例方法）
     */
    public function clear(): bool
    {
        // 清空内存缓存
        $this->memoryCache = [];
        
        if ($this->cacheFp) {
            fclose($this->cacheFp);
            $this->cacheFp = null;
        }
        
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
        
        $this->create();
        
        Logger::info("缓存已清空", ['file' => $this->cacheFile]);
        
        return true;
    }
    
    /**
     * 重建缓存（删除已标记删除的数据，收缩文件大小）
     */
    public function rebuild(bool $isForce = false): bool
    {
        // 强制在凌晨2-6点才允许rebuild操作，避开访问高峰期
        if (!$isForce && (date('G') < 2 || date('G') > 6)) {
            Logger::info("非高峰期，跳过重建");
            return false;
        }
        
        Logger::info("开始重建缓存文件", ['file' => $this->cacheFile]);
        
        $this->open();
        
        // 收集所有有效数据
        $validData = [];
        
        for ($i = 0; $i <= $this->maskValue; $i++) {
            fseek($this->cacheFp, $i * 4 + $this->exitCodeLength);
            $darr = unpack('l1h', fread($this->cacheFp, 4));
            $headPos = $darr['h'];
            
            if ($headPos == 0) {
                continue;
            }
            
            $nPos = $headPos;
            $n = 0;
            
            do {
                fseek($this->cacheFp, $nPos);
                $infoDat = fread($this->cacheFp, $this->metaLength);
                
                if (strlen($infoDat) != $this->metaLength) {
                    break;
                }
                
                $curNode = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $infoDat);
                
                if ($curNode['key_len'] > 0 && $curNode['data_len'] > 0) {
                    $nodeKey = fread($this->cacheFp, $curNode['key_len']);
                    $nodeData = unserialize(fread($this->cacheFp, $curNode['data_len']));
                    
                    // 只保存未过期的数据
                    if ($curNode['exptime'] == 0 || $curNode['time'] + $curNode['exptime'] >= time()) {
                        $validData[] = [
                            'key' => $nodeKey,
                            'value' => $nodeData,
                            'exptime' => $curNode['exptime'] > 0 ? $curNode['time'] + $curNode['exptime'] - time() : 0
                        ];
                    }
                }
                
                $nPos = $curNode['next'];
                $n++;
            } while ($nPos > 0 && $n < $this->linkMax);
        }
        
        // 关闭并删除旧文件
        fclose($this->cacheFp);
        $this->cacheFp = null;
        @unlink($this->cacheFile);
        
        // 创建新文件
        $this->create();
        
        // 重新写入有效数据
        foreach ($validData as $item) {
            $this->setValue($item['key'], $item['value'], $item['exptime']);
        }
        
        Logger::info("缓存重建完成", [
            'file' => $this->cacheFile,
            'valid_items' => count($validData)
        ]);
        
        return true;
    }
    
    /**
     * 关闭文件
     */
    public function close(): void
    {
        if ($this->cacheFp) {
            @fclose($this->cacheFp);
            $this->cacheFp = null;
        }
    }
    
    /**
     * 工厂方法创建实例
     */
    public static function factory(?string $cacheFile = null, bool $isSingle = false): self
    {
        return new self($cacheFile, $isSingle);
    }
    
    /**
     * 获取缓存统计信息
     */
    public function getStats(): array
    {
        $this->open();
        
        $totalItems = 0;
        $validItems = 0;
        $expiredItems = 0;
        $deletedItems = 0;
        $totalSize = filesize($this->cacheFile);
        
        for ($i = 0; $i <= $this->maskValue; $i++) {
            fseek($this->cacheFp, $i * 4 + $this->exitCodeLength);
            $darr = unpack('l1h', fread($this->cacheFp, 4));
            $headPos = $darr['h'];
            
            if ($headPos == 0) {
                continue;
            }
            
            $nPos = $headPos;
            $n = 0;
            
            do {
                fseek($this->cacheFp, $nPos);
                $infoDat = fread($this->cacheFp, $this->metaLength);
                
                if (strlen($infoDat) != $this->metaLength) {
                    break;
                }
                
                $curNode = unpack('S1key_len/l1data_len/l1pre/l1next/l1time/l1exptime', $infoDat);
                
                $totalItems++;
                
                if ($curNode['data_len'] == 0) {
                    $deletedItems++;
                } else if ($curNode['exptime'] > 0 && $curNode['time'] + $curNode['exptime'] < time()) {
                    $expiredItems++;
                } else {
                    $validItems++;
                }
                
                $nPos = $curNode['next'];
                $n++;
            } while ($nPos > 0 && $n < $this->linkMax);
        }
        
        return [
            'total_items' => $totalItems,
            'valid_items' => $validItems,
            'expired_items' => $expiredItems,
            'deleted_items' => $deletedItems,
            'file_size' => $totalSize,
            'file_size_mb' => round($totalSize / 1024 / 1024, 2),
            'memory_cache_items' => count($this->memoryCache),
        ];
    }
}
