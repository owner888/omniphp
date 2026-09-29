<?php
/**
 * 数据库管理器
 * 
 * 简化数据库初始化，自动根据配置选择驱动
 */

namespace OmniPHP\Database;

use PDO;
use OmniPHP\Config;
use OmniPHP\Logger;

class DatabaseManager
{
    /** @var PDO[] 额外数据库连接缓存 */
    private static array $connections = [];
    
    /**
     * 获取额外数据库连接（支持多数据库）
     * 
     * 使用 config/database.php 中的命名配置，连接会被缓存复用。
     * 不影响主库 DB::connection()。
     * 
     * @param string $name 配置名称，如 'agccam'
     * @return PDO
     */
    public static function connection(string $name): PDO
    {
        if (isset(self::$connections[$name])) {
            // 轻量检查连接状态（C 库级别 ping，不走 SQL 解析器）
            try {
                self::$connections[$name]->getAttribute(PDO::ATTR_SERVER_INFO);
                return self::$connections[$name];
            } catch (\PDOException $e) {
                Logger::warning("[DB] Connection '{$name}' lost, reconnecting...");
                unset(self::$connections[$name]);
                // 继续往下重建连接
            }
        }
        
        $config = Config::get("database.{$name}");
        if (!$config) {
            throw new \Exception("Database config not found: {$name}");
        }
        
        $config['driver'] = $config['driver'] ?? 'mysql';
        self::$connections[$name] = self::createPdo($config);
        Logger::debug("[DB] Connection '{$name}' established", ['driver' => $config['driver']]);
        return self::$connections[$name];
    }
    
    /**
     * 关闭额外数据库连接
     */
    public static function closeConnection(string $name): void
    {
        unset(self::$connections[$name]);
    }
    
    /**
     * 关闭所有额外连接
     */
    public static function closeAll(): void
    {
        self::$connections = [];
    }
    
    /**
     * 初始化数据库连接
     * 
     * @param string|null $driver 驱动名称（mysql, sqlite），null 则使用配置的默认值
     * @return PDO
     */
    public static function connect(?string $driver = null): PDO
    {
        // 获取驱动
        $driver = $driver ?? Config::get('database.default', 'sqlite');
        
        // 获取配置
        $config = Config::get("database.{$driver}");
        
        if (!$config) {
            throw new \Exception("Database config not found for driver: {$driver}");
        }
        
        // 添加驱动信息到配置
        $config['driver'] = $driver;
        
        // 创建 PDO 连接
        $pdo = self::createPdo($config);
        
        // 初始化 DB 类
        DB::init($pdo, $config);
        
        return $pdo;
    }
    
    /**
     * 创建 PDO 实例
     */
    private static function createPdo(array $config): PDO
    {
        $driver = $config['driver'];
        
        switch ($driver) {
            case 'sqlite':
                return self::createSqlitePdo($config);
                
            case 'mysql':
                return self::createMysqlPdo($config);
                
            default:
                throw new \Exception("Unsupported database driver: {$driver}");
        }
    }
    
    /**
     * 创建 SQLite PDO 连接
     */
    private static function createSqlitePdo(array $config): PDO
    {
        $database = $config['database'] ?? ':memory:';
        
        // 如果是文件数据库，确保目录存在
        if ($database !== ':memory:') {
            $dir = dirname($database);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        $dsn = "sqlite:{$database}";
        
        $pdo = new PDO($dsn, '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        // SQLite 优化配置
        $pdo->exec('PRAGMA foreign_keys = ON');           // 启用外键约束
        $pdo->exec('PRAGMA journal_mode = WAL');          // WAL 模式（更好的并发）
        $pdo->exec('PRAGMA synchronous = FULL');          // 完全同步模式（确保数据持久化，防止非正常关闭丢数据）
        $pdo->exec('PRAGMA busy_timeout = 5000');         // 锁等待超时 5 秒（防止并发写入直接报错）
        $pdo->exec('PRAGMA cache_size = -64000');         // 缓存大小 64MB
        $pdo->exec('PRAGMA temp_store = MEMORY');         // 临时表存储在内存
        
        return $pdo;
    }
    
    /**
     * 创建 MySQL PDO 连接
     */
    private static function createMysqlPdo(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? 'localhost',
            $config['port'] ?? 3306,
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );
        
        $pdo = new PDO(
            $dsn,
            $config['username'] ?? '',
            $config['password'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                (defined('Pdo\Mysql::ATTR_INIT_COMMAND') ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND) => "SET NAMES {$config['charset']} COLLATE {$config['collation']}",
            ]
        );
        
        return $pdo;
    }
    
    /**
     * 快速初始化（使用默认配置）
     */
    public static function init(): void
    {
        self::connect();
    }
}
