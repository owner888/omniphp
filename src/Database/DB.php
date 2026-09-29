<?php
/**
 * 数据库连接管理器
 * 
 * 使用示例：
 * DB::init($pdo);
 * $users = DB::table('users')->where('age', '>', 18)->get();
 */

namespace OmniPHP\Database;

use PDO;
use OmniPHP\Logger;

class DB
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    
    /** @var bool 是否在事务中 */
    private static bool $inTransaction = false;
    
    /** @var bool 是否启用查询日志 */
    private static bool $logging = false;
    
    /** @var array 查询日志 */
    private static array $queryLog = [];
    
    /** @var float 慢查询阈值（秒），超过则记录警告日志。默认 1 秒 */
    private static float $slowThreshold = 1.0;
    
    /** @var int 查询日志最大条数（防止内存泄漏） */
    private static int $maxLogSize = 1000;
    
    /**
     * 初始化数据库连接
     */
    public static function init(PDO $pdo, array $config = []): void
    {
        self::$pdo = $pdo;
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // 保存配置用于重连
        self::$config = $config;
    }
    
    /**
     * 检查默认数据库是否已初始化
     */
    public static function isInitialized(): bool
    {
        return self::$pdo !== null;
    }
    
    /**
     * 获取 PDO 实例
     */
    public static function connection(): PDO
    {
        if (!self::$pdo) {
            throw new \Exception('Database not initialized. Call DB::init($pdo) first.');
        }
        
        return self::$pdo;
    }
    
    /**
     * 关闭数据库连接
     * 
     * 重要：在 Workerman fork 之前必须关闭主进程的数据库连接！
     * 
     * SQLite 使用文件锁来管理并发，锁是绑定在 文件描述符(fd) 上的。
     * fork() 后子进程会继承父进程的 fd，导致多个进程共享同一个 fd。
     * 当多个进程通过同一个 fd 写入 SQLite 时：
     * - SQLite 的锁机制被绕过（锁是 per-fd 的，不是 per-process 的）
     * - WAL 文件可能被多个进程同时修改，导致数据损坏
     * - 已提交的数据可能在 checkpoint 时丢失
     * 
     * 正确做法：
     * 1. 主进程：init() → 执行迁移等初始化 → close() → fork
     * 2. 子进程：在 onWorkerStart 中调用 initWorker() 创建独立连接
     */
    public static function close(): void
    {
        self::$pdo = null;
        self::$inTransaction = false;
    }
    
    /**
     * 检查是否是连接丢失错误
     * 
     * 同时检查错误码和错误消息，确保在各种 PDO 驱动下都能正确识别
     */
    public static function isConnectionError(\PDOException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $errorCode = $errorInfo[1] ?? 0;
        
        // MySQL 连接丢失的错误码
        // 2006 - MySQL server has gone away
        // 2013 - Lost connection to MySQL server during query
        // 2055 - Lost connection to MySQL server at 'reading initial communication packet'
        // 1927 - Connection was killed
        if (in_array($errorCode, [2006, 2013, 2055, 1927])) {
            return true;
        }
        
        // 某些驱动不正确填充 errorInfo，需要检查错误消息
        $message = strtolower($e->getMessage());
        $connectionErrors = [
            'server has gone away',
            'lost connection',
            'connection was killed',
            'broken pipe',
            'connection reset by peer',
            'no connection to the server',
            'decryption failed or bad record mac',
            'ssl connection has been closed unexpectedly',
        ];
        
        foreach ($connectionErrors as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 是否在事务中
     */
    public static function inTransaction(): bool
    {
        return self::$inTransaction;
    }
    
    /**
     * 重新连接数据库
     */
    public static function reconnect(): void
    {
        if (empty(self::$config)) {
            throw new \Exception('Cannot reconnect: No database config provided to init()');
        }
        
        try {
            $config = self::$config;
            $driver = $config['driver'] ?? 'mysql';
            
            // 根据驱动构建 DSN
            $dsn = self::buildDsn($config);
            
            self::$pdo = new PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // 禁用持久连接（在 Workerman 中不需要）
                    PDO::ATTR_PERSISTENT => false,
                ]
            );
            
            // SQLite 特定配置
            if ($driver === 'sqlite') {
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA synchronous = FULL');
                self::$pdo->exec('PRAGMA busy_timeout = 5000');
            }
            
            Logger::debug("[DB] reconnected successfully");
        } catch (\PDOException $e) {
            Logger::error("[DB] Reconnect failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 构建 DSN 字符串
     */
    private static function buildDsn(array $config): string
    {
        $driver = $config['driver'] ?? 'mysql';
        
        switch ($driver) {
            case 'sqlite':
                // SQLite: sqlite:/path/to/database.db
                $path = $config['database'] ?? ':memory:';
                return "sqlite:{$path}";
                
            case 'mysql':
                // MySQL: mysql:host=localhost;port=3306;dbname=test;charset=utf8mb4
                return sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? 3306,
                    $config['database'] ?? '',
                    $config['charset'] ?? 'utf8mb4'
                );
                
            default:
                throw new \Exception("Unsupported database driver: {$driver}");
        }
    }
    
    /**
     * 转义标识符（表名、列名）
     * 
     * 防止 SQL 注入，表名和列名不能使用预处理语句的占位符
     * 需要根据数据库类型使用不同的引号
     * 
     * @param string $identifier 标识符（表名或列名）
     * @return string 转义后的标识符
     */
    public static function escapeIdentifier(string $identifier, ?PDO $pdo = null): string
    {
        // 移除危险字符，只允许字母、数字、下划线
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
        
        $driver = ($pdo ?? self::connection())->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        return match($driver) {
            'mysql' => "`{$identifier}`",           // MySQL 使用反引号
            'pgsql' => "\"{$identifier}\"",         // PostgreSQL 使用双引号
            'sqlite' => "\"{$identifier}\"",        // SQLite 使用双引号
            default => $identifier,
        };
    }
    
    /**
     * 创建查询构建器
     */
    public static function table(string $table): QueryBuilder
    {
        return new QueryBuilder(self::connection(), $table);
    }
    
    /**
     * 执行原始 SQL（带自动重连，事务中不重连）
     */
    public static function query(string $sql, array $bindings = []): array
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // 事务中不自动重连（重连后事务状态会丢失，必须让上层重试整个事务）
            if (!self::$inTransaction && self::isConnectionError($e)) {
                Logger::warning("[DB] Connection lost, reconnecting for query...");
                self::reconnect();
                $stmt = self::connection()->prepare($sql);
                $stmt->execute($bindings);
                return $stmt->fetchAll();
            }
            throw $e;
        }
    }
    
    /**
     * 执行插入/更新/删除（带自动重连，事务中不重连）
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // 事务中不自动重连
            if (!self::$inTransaction && self::isConnectionError($e)) {
                Logger::warning("[DB] Connection lost, reconnecting for execute...");
                self::reconnect();
                $stmt = self::connection()->prepare($sql);
                $stmt->execute($bindings);
                return $stmt->rowCount();
            }
            throw $e;
        }
    }
    
    /**
     * 闭包式事务（自动 commit/rollback，连接断开自动重连重试）
     * 
     * 用法: DB::transaction(function() {
     *          DB::table('users')->where('id', 1)->update(['balance' => 100]);
     *          DB::table('logs')->insert(['action' => 'update_balance']);
     *       });
     * 
     * @param callable $callback 事务闭包
     * @param int $retries 连接丢失时的最大重试次数
     * @return mixed 闭包的返回值
     */
    public static function transaction(callable $callback, int $retries = 1): mixed
    {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            self::beginTransaction();
            
            try {
                $result = $callback();
                self::commit();
                return $result;
            } catch (\PDOException $e) {
                self::rollBack();
                
                // 连接丢失且还有重试次数 → 重连后重试整个事务
                if ($attempt < $retries && self::isConnectionError($e)) {
                    Logger::warning("[DB] Transaction failed due to connection loss, retrying (attempt " . ($attempt + 1) . ")...");
                    self::reconnect();
                    continue;
                }
                
                throw $e;
            } catch (\Exception $e) {
                self::rollBack();
                throw $e;
            }
        }
        
        throw new \Exception('Transaction failed after all retries');
    }
    
    /**
     * 开始事务
     */
    public static function beginTransaction(): bool
    {
        self::$inTransaction = true;
        return self::connection()->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public static function commit(): bool
    {
        self::$inTransaction = false;
        return self::connection()->commit();
    }
    
    /**
     * 回滚事务
     */
    public static function rollBack(): bool
    {
        self::$inTransaction = false;
        try {
            return self::connection()->rollBack();
        } catch (\PDOException $e) {
            // 连接已断开，无法回滚（没关系，MySQL 连接断开会自动回滚未提交的事务）
            Logger::warning("[DB] RollBack failed (connection may be lost): " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取最后插入的 ID
     */
    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }
    
    // ==================== 查询日志 & 慢查询检测 ====================
    
    /**
     * 启用查询日志
     * 
     * 用法: DB::enableQueryLog();
     *       // ... 执行查询 ...
     *       $logs = DB::getQueryLog();
     */
    public static function enableQueryLog(): void
    {
        self::$logging = true;
    }
    
    /**
     * 关闭查询日志
     */
    public static function disableQueryLog(): void
    {
        self::$logging = false;
    }
    
    /**
     * 设置慢查询阈值（秒）
     * 
     * 用法: DB::setSlowThreshold(0.5);  // 超过 500ms 记录警告
     */
    public static function setSlowThreshold(float $seconds): void
    {
        self::$slowThreshold = $seconds;
    }
    
    /**
     * 设置日志最大条数
     */
    public static function setMaxLogSize(int $size): void
    {
        self::$maxLogSize = $size;
    }
    
    /**
     * 记录查询日志（由 QueryBuilder 调用）
     * 
     * @param string $sql SQL 语句
     * @param array $bindings 绑定参数
     * @param float $time 执行耗时（秒）
     */
    public static function logQuery(string $sql, array $bindings, float $time): void
    {
        // 慢查询检测（始终开启，不依赖 logging 开关）
        if ($time >= self::$slowThreshold) {
            $timeMs = round($time * 1000, 2);
            $bindStr = empty($bindings) ? '' : ' | Bindings: ' . json_encode($bindings, JSON_UNESCAPED_UNICODE);
            Logger::warning("[DB SLOW] {$timeMs}ms | {$sql}{$bindStr}");
        }
        
        // 查询日志记录（需要开启 logging）
        if (self::$logging) {
            // 防止内存泄漏
            if (count(self::$queryLog) >= self::$maxLogSize) {
                array_shift(self::$queryLog);
            }
            
            self::$queryLog[] = [
                'sql'      => $sql,
                'bindings' => $bindings,
                'time'     => round($time * 1000, 2), // 毫秒
                'slow'     => $time >= self::$slowThreshold,
            ];
        }
    }
    
    /**
     * 获取查询日志
     * 
     * 返回: [
     *   ['sql' => 'SELECT ...', 'bindings' => [...], 'time' => 12.5, 'slow' => false],
     *   ...
     * ]
     */
    public static function getQueryLog(): array
    {
        return self::$queryLog;
    }
    
    /**
     * 获取慢查询日志
     */
    public static function getSlowQueries(): array
    {
        return array_filter(self::$queryLog, fn($log) => $log['slow']);
    }
    
    /**
     * 获取查询统计
     * 
     * 返回: ['total_queries' => 42, 'total_time' => 156.7, 'slow_queries' => 2, 'avg_time' => 3.7]
     */
    public static function getQueryStats(): array
    {
        $total = count(self::$queryLog);
        $totalTime = array_sum(array_column(self::$queryLog, 'time'));
        $slowCount = count(array_filter(self::$queryLog, fn($log) => $log['slow']));
        
        return [
            'total_queries' => $total,
            'total_time'    => round($totalTime, 2),     // 总耗时(ms)
            'slow_queries'  => $slowCount,
            'avg_time'      => $total > 0 ? round($totalTime / $total, 2) : 0,  // 平均耗时(ms)
        ];
    }
    
    /**
     * 清空查询日志
     */
    public static function flushQueryLog(): void
    {
        self::$queryLog = [];
    }
    
    /**
     * 是否已启用查询日志
     */
    public static function isLogging(): bool
    {
        return self::$logging;
    }
}
