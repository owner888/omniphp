<?php
/**
 * Schema 构建器
 * 
 * 用于创建和修改数据库表结构
 */

namespace OmniPHP\Database;

use PDO;

class Schema
{
    /**
     * 创建表
     */
    public static function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql();
        DB::execute($sql);
        
        // 创建索引（toSql() 只生成 CREATE TABLE，索引需要单独执行）
        foreach ($blueprint->getIndexes() as $idx) {
            $type = $idx['unique'] ? 'UNIQUE INDEX' : 'INDEX';
            $escapedIndexName = DB::escapeIdentifier($idx['name']);
            $escapedTable = DB::escapeIdentifier($table);
            $escapedColumns = implode(', ', array_map(fn($col) => DB::escapeIdentifier($col), $idx['columns']));
            
            DB::execute("CREATE {$type} {$escapedIndexName} ON {$escapedTable} ({$escapedColumns})");
        }
    }
    
    /**
     * 修改表（自动跳过已存在的列和索引，不会报错或丢数据）
     */
    public static function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, 'alter');
        $callback($blueprint);
        
        $operations = $blueprint->getAlterOperations();
        
        foreach ($operations as $op) {
            switch ($op['type']) {
                case 'add_column':
                    if (!self::hasColumn($table, $op['column'])) {   // 已存在跳过（幂等）
                        DB::execute($op['sql']);
                    }
                    break;
                case 'add_index':
                    if (!self::hasIndex($table, $op['index'])) {     // 已存在跳过（幂等）
                        DB::execute($op['sql']);
                    }
                    break;
                // drop / rename 委托给健壮的静态实现：含 SQLite 老版本重建表兜底 + 内部存在性 guard，
                // 比裸 ALTER 稳。Blueprint 实例方法只负责"记录意图"，执行落到 Schema:: 静态版。
                case 'drop_column':
                    self::dropColumn($table, $op['column']);
                    break;
                case 'drop_index':
                    self::dropIndex($table, $op['index']);
                    break;
                case 'rename_column':
                    self::renameColumn($table, $op['from'], $op['to']);
                    break;
                default:
                    DB::execute($op['sql']);
            }
        }
    }
    
    /**
     * 删除表
     */
    public static function drop(string $table): void
    {
        $escapedTable = DB::escapeIdentifier($table);
        DB::execute("DROP TABLE IF EXISTS {$escapedTable}");
    }
    
    /**
     * 删除表（如果存在）
     */
    public static function dropIfExists(string $table): void
    {
        self::drop($table);
    }
    
    /**
     * 删除列（兼容旧版 SQLite）
     * 
     * SQLite < 3.35.0 不支持 ALTER TABLE DROP COLUMN，
     * 需要重建表来删除列（数据不丢失）
     */
    public static function dropColumn(string $table, string|array $columns): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'mysql') {
            // MySQL 直接 DROP COLUMN
            foreach ($columns as $col) {
                if (self::hasColumn($table, $col)) {
                    $escapedTable = DB::escapeIdentifier($table);
                    $escapedCol = DB::escapeIdentifier($col);
                    DB::execute("ALTER TABLE {$escapedTable} DROP COLUMN {$escapedCol}");
                }
            }
            return;
        }
        
        // SQLite: 通过重建表来删除列
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        // 1. 获取当前表的所有列信息
        $stmt = $pdo->prepare("PRAGMA table_info({$cleanTable})");
        $stmt->execute();
        $allColumns = $stmt->fetchAll();
        
        // 2. 过滤掉要删除的列
        $keepColumns = [];
        $columnDefs = [];
        foreach ($allColumns as $col) {
            if (in_array($col['name'], $columns)) {
                continue; // 跳过要删除的列
            }
            $keepColumns[] = $col['name'];
            
            // 重建列定义
            $def = DB::escapeIdentifier($col['name']) . ' ' . $col['type'];
            if ($col['notnull']) {
                $def .= ' NOT NULL';
            }
            if ($col['dflt_value'] !== null) {
                $def .= ' DEFAULT ' . $col['dflt_value'];
            }
            if ($col['pk']) {
                $def .= ' PRIMARY KEY AUTOINCREMENT';
            }
            $columnDefs[] = $def;
        }
        
        if (empty($keepColumns)) {
            return; // 不能删除所有列
        }
        
        $escapedTable = DB::escapeIdentifier($table);
        $tempTable = DB::escapeIdentifier($cleanTable . '_backup_' . time());
        $keepColumnsEscaped = implode(', ', array_map(fn($c) => DB::escapeIdentifier($c), $keepColumns));
        $columnDefsStr = implode(', ', $columnDefs);
        
        // 3. 创建临时表 → 复制数据 → 删除旧表 → 重命名
        // 必须在事务中执行，防止中途失败导致数据丢失（如 DROP 后 RENAME 前崩溃）
        DB::transaction(function() use ($tempTable, $columnDefsStr, $keepColumnsEscaped, $escapedTable) {
            DB::execute("CREATE TABLE {$tempTable} ({$columnDefsStr})");
            DB::execute("INSERT INTO {$tempTable} ({$keepColumnsEscaped}) SELECT {$keepColumnsEscaped} FROM {$escapedTable}");
            DB::execute("DROP TABLE {$escapedTable}");
            DB::execute("ALTER TABLE {$tempTable} RENAME TO {$escapedTable}");
        });
    }
    
    /**
     * 重命名列（兼容旧版 SQLite）
     * 
     * SQLite 3.25.0+ 支持 ALTER TABLE RENAME COLUMN
     * 旧版 SQLite 通过重建表实现
     */
    public static function renameColumn(string $table, string $from, string $to): void
    {
        if (!self::hasColumn($table, $from)) {
            return; // 原列不存在，跳过
        }
        
        if (self::hasColumn($table, $to)) {
            return; // 目标列已存在，跳过
        }
        
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $escapedTable = DB::escapeIdentifier($table);
        $escapedFrom = DB::escapeIdentifier($from);
        $escapedTo = DB::escapeIdentifier($to);
        
        if ($driver === 'mysql') {
            // MySQL: 需要获取列类型来 CHANGE COLUMN
            $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $cleanFrom = preg_replace('/[^a-zA-Z0-9_]/', '', $from);
            
            $stmt = $pdo->prepare(
                "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$cleanTable, $cleanFrom]);
            $colInfo = $stmt->fetch();
            
            if ($colInfo) {
                $colType = $colInfo['COLUMN_TYPE'];
                $nullable = $colInfo['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
                $default = $colInfo['COLUMN_DEFAULT'] !== null ? "DEFAULT " . $pdo->quote($colInfo['COLUMN_DEFAULT']) : '';
                
                DB::execute("ALTER TABLE {$escapedTable} CHANGE COLUMN {$escapedFrom} {$escapedTo} {$colType} {$nullable} {$default}");
            }
        } else {
            // SQLite: 尝试 RENAME COLUMN（3.25.0+），失败则重建表
            try {
                DB::execute("ALTER TABLE {$escapedTable} RENAME COLUMN {$escapedFrom} TO {$escapedTo}");
            } catch (\Exception $e) {
                // 旧版 SQLite: 重建表
                $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
                
                $stmt = $pdo->prepare("PRAGMA table_info({$cleanTable})");
                $stmt->execute();
                $allColumns = $stmt->fetchAll();
                
                $oldColumns = [];
                $newColumnDefs = [];
                $newColumns = [];
                
                foreach ($allColumns as $col) {
                    $oldColumns[] = DB::escapeIdentifier($col['name']);
                    
                    $newName = $col['name'] === $from ? $to : $col['name'];
                    $newColumns[] = DB::escapeIdentifier($newName);
                    
                    $def = DB::escapeIdentifier($newName) . ' ' . $col['type'];
                    if ($col['notnull']) $def .= ' NOT NULL';
                    if ($col['dflt_value'] !== null) $def .= ' DEFAULT ' . $col['dflt_value'];
                    if ($col['pk']) $def .= ' PRIMARY KEY AUTOINCREMENT';
                    $newColumnDefs[] = $def;
                }
                
                $tempTable = DB::escapeIdentifier($cleanTable . '_rename_' . time());
                $oldColStr = implode(', ', $oldColumns);
                $newColStr = implode(', ', $newColumns);
                $defStr = implode(', ', $newColumnDefs);
                
                DB::execute("CREATE TABLE {$tempTable} ({$defStr})");
                DB::execute("INSERT INTO {$tempTable} ({$newColStr}) SELECT {$oldColStr} FROM {$escapedTable}");
                DB::execute("DROP TABLE {$escapedTable}");
                DB::execute("ALTER TABLE {$tempTable} RENAME TO {$escapedTable}");
            }
        }
    }
    
    /**
     * 检查表是否存在
     */
    public static function hasTable(string $table): bool
    {
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        // 过滤表名防止注入
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$cleanTable]);
            return count($stmt->fetchAll()) > 0;
        } elseif ($driver === 'mysql') {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$cleanTable]);
            return count($stmt->fetchAll()) > 0;
        }
        
        return false;
    }
    
    /**
     * 检查列是否存在
     */
    public static function hasColumn(string $table, string $column): bool
    {
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $cleanColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("PRAGMA table_info({$cleanTable})");
                $stmt->execute();
                $columns = $stmt->fetchAll();
                foreach ($columns as $col) {
                    if ($col['name'] === $cleanColumn) {
                        return true;
                    }
                }
                return false;
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$cleanTable, $cleanColumn]);
                return count($stmt->fetchAll()) > 0;
            }
        } catch (\Exception $e) {
            return false;
        }
        
        return false;
    }
    
    /**
     * 创建索引（跨数据库兼容）
     * 
     * 用法: Schema::createIndex('users', 'device_id', 'idx_users_device_id')
     *       Schema::createIndex('users', ['status', 'created_at'], 'idx_users_status_time')
     */
    public static function createIndex(string $table, string|array $columns, string $indexName, bool $unique = false): void
    {
        if (self::hasIndex($table, $indexName)) {
            return; // 已存在则跳过
        }
        
        $columns = is_array($columns) ? $columns : [$columns];
        $escapedTable = DB::escapeIdentifier($table);
        $escapedIndex = DB::escapeIdentifier($indexName);
        $escapedColumns = implode(', ', array_map(fn($c) => DB::escapeIdentifier($c), $columns));
        $type = $unique ? 'UNIQUE INDEX' : 'INDEX';
        
        DB::execute("CREATE {$type} {$escapedIndex} ON {$escapedTable} ({$escapedColumns})");
    }
    
    /**
     * 创建唯一索引（跨数据库兼容）
     */
    public static function createUniqueIndex(string $table, string|array $columns, string $indexName): void
    {
        self::createIndex($table, $columns, $indexName, true);
    }
    
    /**
     * 删除索引（跨数据库兼容）
     * 
     * SQLite: DROP INDEX IF EXISTS idx_name
     * MySQL:  ALTER TABLE table_name DROP INDEX idx_name (先检查存在性)
     */
    public static function dropIndex(string $table, string $indexName): void
    {
        if (!self::hasIndex($table, $indexName)) {
            return;
        }
        
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        $escapedTable = DB::escapeIdentifier($table);
        $escapedIndex = DB::escapeIdentifier($indexName);
        
        if ($driver === 'mysql') {
            DB::execute("ALTER TABLE {$escapedTable} DROP INDEX {$escapedIndex}");
        } else {
            DB::execute("DROP INDEX IF EXISTS {$escapedIndex}");
        }
    }
    
    /**
     * 检查索引是否存在
     */
    public static function hasIndex(string $table, string $indexName): bool
    {
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $cleanIndex = preg_replace('/[^a-zA-Z0-9_]/', '', $indexName);
        
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?");
                $stmt->execute([$cleanTable, $cleanIndex]);
                return count($stmt->fetchAll()) > 0;
            } elseif ($driver === 'mysql') {
                $stmt = $pdo->prepare(
                    "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
                );
                $stmt->execute([$cleanTable, $cleanIndex]);
                return count($stmt->fetchAll()) > 0;
            }
        } catch (\Exception $e) {
            return false;
        }
        
        return false;
    }
}

/**
 * Blueprint - 表结构构建器
 */
class Blueprint
{
    private string $table;
    private string $mode; // 'create' or 'alter'
    private array $columns = [];
    private array $indexes = [];
    private array $dropColumns = [];   // alter 模式：要删的列名
    private array $dropIndexes = [];   // alter 模式：要删的索引名
    private array $renameColumns = []; // alter 模式：[['from'=>.., 'to'=>..], ...]
    private ?string $primaryKey = null;
    
    public function __construct(string $table, string $mode = 'create')
    {
        // 转义表名防止 SQL 注入
        $this->table = DB::escapeIdentifier($table);
        $this->mode = $mode;
    }
    
    /**
     * 自增主键
     */
    public function id(string $name = 'id'): self
    {
        $this->primaryKey = $name;
        // 对齐 Laravel id()(=bigIncrements)：MySQL BIGINT UNSIGNED。
        // ⚠️ SQLite 必须 INTEGER —— 只有 INTEGER PRIMARY KEY 才是 rowid 别名，AUTOINCREMENT 才生效。
        $driver = DB::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->columns[] = [
            'name' => DB::escapeIdentifier($name),
            'type' => $driver === 'mysql' ? 'BIGINT' : 'INTEGER',
            'unsigned' => $driver === 'mysql',
            'nullable' => false,
            'primary' => true,
            'autoIncrement' => true,
        ];
        return $this;
    }
    
    /**
     * 整数字段
     */
    public function integer(string $name): Column
    {
        return $this->addColumn($name, 'INTEGER');
    }
    
    /**
     * 小整数字段（MySQL TINYINT，SQLite 用 INTEGER）
     */
    public function tinyInteger(string $name): Column
    {
        return $this->addColumn($name, 'TINYINT');
    }
    
    /**
     * 大整数字段
     */
    public function bigInteger(string $name): Column
    {
        return $this->addColumn($name, 'BIGINT');
    }
    
    /**
     * 浮点字段
     */
    public function float(string $name): Column
    {
        return $this->addColumn($name, 'FLOAT');
    }
    
    /**
     * 双精度浮点字段
     */
    public function double(string $name): Column
    {
        return $this->addColumn($name, 'DOUBLE');
    }
    
    /**
     * 精确小数字段（适合金额等精确计算）
     * 
     * 用法: $table->decimal('price', 10, 2)  → DECIMAL(10,2)
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): Column
    {
        return $this->addColumn($name, "DECIMAL({$precision},{$scale})");
    }
    
    /**
     * JSON 字段（MySQL 5.7+ 原生 JSON，SQLite 用 TEXT 存储）
     */
    public function json(string $name): Column
    {
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $type = $driver === 'mysql' ? 'JSON' : 'TEXT';
        return $this->addColumn($name, $type);
    }
    
    /**
     * 字符串字段
     */
    public function string(string $name, int $length = 255): Column
    {
        return $this->addColumn($name, "VARCHAR({$length})");
    }
    
    /**
     * 文本字段
     */
    public function text(string $name): Column
    {
        return $this->addColumn($name, 'TEXT');
    }
    
    /**
     * 布尔字段
     */
    public function boolean(string $name): Column
    {
        return $this->addColumn($name, 'BOOLEAN');
    }
    
    /**
     * 日期字段（纯日期，无时间）。对齐 Laravel Blueprint::date()。
     */
    public function date(string $name): Column
    {
        return $this->addColumn($name, 'DATE');
    }

    /**
     * 日期时间字段。声明名对齐 Laravel Blueprint::dateTime()。
     * PHP 方法名大小写不敏感 → 老调用 $table->datetime() 照常解析到这里，零改动。
     * ⚠️ 不能再单独声明小写 datetime()（会 Fatal: Cannot redeclare）。
     */
    public function dateTime(string $name): Column
    {
        return $this->addColumn($name, 'DATETIME');
    }

    /**
     * 时间戳字段
     */
    public function timestamp(string $name): Column
    {
        return $this->addColumn($name, 'TIMESTAMP');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Laravel Blueprint 列类型补齐（对齐 Illuminate\Database\Schema\Blueprint）
    //  —— 凭 Laravel 记忆写 migration 不再撞 undefined method。
    //  未补（用别的方式）：increments/bigIncrements（用 id()）、morphs/foreignId（本项目不走
    //  Laravel FK 约定）、spatial 类型（geometry/point... 极少用）。需要再问 user 单独加。
    // ════════════════════════════════════════════════════════════════════════

    /** 时间字段（无日期） */
    public function time(string $name): Column
    {
        return $this->addColumn($name, 'TIME');
    }

    /** 年份字段 */
    public function year(string $name): Column
    {
        return $this->addColumn($name, 'YEAR');
    }

    /** 小整数 SMALLINT */
    public function smallInteger(string $name): Column
    {
        return $this->addColumn($name, 'SMALLINT');
    }

    /** 中整数 MEDIUMINT */
    public function mediumInteger(string $name): Column
    {
        return $this->addColumn($name, 'MEDIUMINT');
    }

    /** 无符号整数族（= 对应整数 + ->unsigned()） */
    public function unsignedInteger(string $name): Column       { return $this->integer($name)->unsigned(); }
    public function unsignedBigInteger(string $name): Column    { return $this->bigInteger($name)->unsigned(); }
    public function unsignedTinyInteger(string $name): Column   { return $this->tinyInteger($name)->unsigned(); }
    public function unsignedSmallInteger(string $name): Column  { return $this->smallInteger($name)->unsigned(); }
    public function unsignedMediumInteger(string $name): Column { return $this->mediumInteger($name)->unsigned(); }

    /** 无符号精确小数 */
    public function unsignedDecimal(string $name, int $precision = 8, int $scale = 2): Column
    {
        return $this->decimal($name, $precision, $scale)->unsigned();
    }

    /** 定长字符串 CHAR */
    public function char(string $name, int $length = 255): Column
    {
        return $this->addColumn($name, "CHAR({$length})");
    }

    /** 文本族 */
    public function tinyText(string $name): Column   { return $this->addColumn($name, 'TINYTEXT'); }
    public function mediumText(string $name): Column { return $this->addColumn($name, 'MEDIUMTEXT'); }
    public function longText(string $name): Column   { return $this->addColumn($name, 'LONGTEXT'); }

    /** ENUM('a','b',...)；SQLite 退化成 VARCHAR */
    public function enum(string $name, array $values): Column
    {
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $quoted = implode(',', array_map(fn($v) => "'" . addslashes((string)$v) . "'", $values));
        $type = $driver === 'mysql' ? "ENUM({$quoted})" : 'VARCHAR(255)';
        return $this->addColumn($name, $type);
    }

    /** SET('a','b',...)；SQLite 退化成 VARCHAR */
    public function set(string $name, array $values): Column
    {
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $quoted = implode(',', array_map(fn($v) => "'" . addslashes((string)$v) . "'", $values));
        $type = $driver === 'mysql' ? "SET({$quoted})" : 'VARCHAR(255)';
        return $this->addColumn($name, $type);
    }

    /** jsonb —— MySQL 无 jsonb，映射 JSON（SQLite TEXT） */
    public function jsonb(string $name): Column
    {
        return $this->json($name);
    }

    /** UUID（CHAR(36)） / ULID（CHAR(26)） */
    public function uuid(string $name = 'uuid'): Column { return $this->addColumn($name, 'CHAR(36)'); }
    public function ulid(string $name = 'ulid'): Column { return $this->addColumn($name, 'CHAR(26)'); }

    /** IP 地址（VARCHAR(45)，容纳 IPv6） */
    public function ipAddress(string $name = 'ip_address'): Column
    {
        return $this->addColumn($name, 'VARCHAR(45)');
    }

    /** MAC 地址（VARCHAR(17)） */
    public function macAddress(string $name = 'mac_address'): Column
    {
        return $this->addColumn($name, 'VARCHAR(17)');
    }

    /** 二进制 BLOB */
    public function binary(string $name): Column
    {
        return $this->addColumn($name, 'BLOB');
    }

    /** Laravel auth 的 remember_token：VARCHAR(100) nullable */
    public function rememberToken(): Column
    {
        return $this->addColumn('remember_token', 'VARCHAR(100)')->nullable();
    }

    // ════════════════════════════════════════════════════════════════════════

    /**
     * 自动添加 created_at 和 updated_at
     * 数据库自动管理时间戳
     */
    public function timestamps(): self
    {
        // created_at - 插入时自动设置
        $col = $this->addColumn('created_at', 'DATETIME');
        $col->definition['default'] = 'CURRENT_TIMESTAMP';
        $col->definition['nullable'] = false;
        
        // updated_at - 更新时自动更新
        $col = $this->addColumn('updated_at', 'DATETIME');
        $col->definition['default'] = 'CURRENT_TIMESTAMP';
        $col->definition['onUpdate'] = 'CURRENT_TIMESTAMP';
        $col->definition['nullable'] = false;
        
        return $this;
    }
    
    /**
     * 软删除字段
     */
    public function softDeletes(): self
    {
        $this->dateTime('deleted_at')->nullable();
        return $this;
    }
    
    /**
     * 添加索引
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'idx_' . implode('_', $columns);
        
        $this->indexes[] = [
            'name' => $name,
            'columns' => $columns,
            'unique' => false,
        ];
        
        return $this;
    }
    
    /**
     * 添加唯一索引
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'uniq_' . implode('_', $columns);
        
        $this->indexes[] = [
            'name' => $name,
            'columns' => $columns,
            'unique' => true,
        ];

        return $this;
    }

    /** 删除列（alter 模式）。对齐 Laravel $table->dropColumn()，支持单个或数组。 */
    public function dropColumn(string|array $columns): self
    {
        foreach ((array)$columns as $c) {
            $this->dropColumns[] = $c;
        }
        return $this;
    }

    /** 删除索引（alter 模式）。对齐 Laravel $table->dropIndex()（传索引名）。 */
    public function dropIndex(string $name): self
    {
        $this->dropIndexes[] = $name;
        return $this;
    }

    /** 重命名列（alter 模式，MySQL 8 / SQLite 3.25+ 的 RENAME COLUMN）。对齐 Laravel $table->renameColumn()。 */
    public function renameColumn(string $from, string $to): self
    {
        $this->renameColumns[] = ['from' => $from, 'to' => $to];
        return $this;
    }

    /**
     * 添加列
     */
    private function addColumn(string $name, string $type): Column
    {
        $column = new Column($name, $type, $this);
        // 转义列名用于 SQL
        $column->definition['name'] = DB::escapeIdentifier($name);
        $this->columns[] = &$column->definition;
        return $column;
    }
    
    /**
     * 生成创建表的 SQL
     */
    public function toSql(): string
    {
        $lines = [];
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        foreach ($this->columns as $col) {
            $type = $col['type'];
            
            // MySQL UNSIGNED 支持
            if ($driver === 'mysql' && !empty($col['unsigned'])) {
                $type .= ' UNSIGNED';
            }
            
            $line = "{$col['name']} {$type}";
            
            if (!empty($col['primary'])) {
                $line .= ' PRIMARY KEY';
                if (!empty($col['autoIncrement'])) {
                    $line .= ($driver === 'mysql') ? ' AUTO_INCREMENT' : ' AUTOINCREMENT';
                }
            }
            
            if (empty($col['nullable'])) {
                $line .= ' NOT NULL';
            }
            
            if (isset($col['default'])) {
                $line .= " DEFAULT {$col['default']}";
            }
            
            // MySQL 支持 ON UPDATE
            if ($driver === 'mysql' && isset($col['onUpdate'])) {
                $line .= " ON UPDATE {$col['onUpdate']}";
            }
            
            $lines[] = $line;
        }
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (\n  " . implode(",\n  ", $lines) . "\n)";
        
        // SQLite 需要用触发器实现 ON UPDATE
        if ($driver === 'sqlite') {
            foreach ($this->columns as $col) {
                if (isset($col['onUpdate'])) {
                    // 触发器名称：去掉引号即可（引号只是用于 SQL 语句中）
                    // 表名/列名在 SQL 中带引号是完全合法且安全的
                    $triggerName = 'update_' . trim($this->table, '"` ') . '_' . trim($col['name'], '"` ');
                    
                    $sql .= ";\n\n";
                    $sql .= "CREATE TRIGGER IF NOT EXISTS {$triggerName}\n";
                    $sql .= "AFTER UPDATE ON {$this->table}\n";
                    $sql .= "FOR EACH ROW\n";
                    $sql .= "BEGIN\n";
                    $sql .= "  UPDATE {$this->table} SET {$col['name']} = CURRENT_TIMESTAMP WHERE id = NEW.id;\n";
                    $sql .= "END";
                }
            }
        }
        
        return $sql;
    }
    
    /**
     * 获取索引定义（供 Schema::create() 在建表后创建索引）
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }
    
    /**
     * 获取原始表名（去掉转义引号）
     */
    public function getRawTableName(): string
    {
        return trim($this->table, '"`\' ');
    }
    
    /**
     * 获取 ALTER 操作列表（包含类型信息，供 Schema::table() 做存在性检查）
     */
    public function getAlterOperations(): array
    {
        $operations = [];
        $pdo = DB::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        foreach ($this->columns as $col) {
            $type = $col['type'];
            
            // MySQL UNSIGNED 支持
            if ($driver === 'mysql' && !empty($col['unsigned'])) {
                $type .= ' UNSIGNED';
            }
            
            $line = "{$col['name']} {$type}";
            
            if (empty($col['nullable'])) {
                $line .= ' NOT NULL';
            }
            
            if (isset($col['default'])) {
                $line .= " DEFAULT {$col['default']}";
            }
            
            $sql = "ALTER TABLE {$this->table} ADD COLUMN {$line}";
            
            // MySQL AFTER 支持（指定列顺序）
            if ($driver === 'mysql' && isset($col['after'])) {
                $sql .= ' AFTER ' . DB::escapeIdentifier($col['after']);
            }
            
            // 获取原始列名（去掉转义引号）
            $rawColumn = trim($col['name'], '"`\' ');
            
            $operations[] = [
                'type' => 'add_column',
                'column' => $rawColumn,
                'sql' => $sql,
            ];
        }
        
        foreach ($this->indexes as $idx) {
            $type = $idx['unique'] ? 'UNIQUE INDEX' : 'INDEX';
            $escapedIndexName = DB::escapeIdentifier($idx['name']);
            $escapedColumns = implode(', ', array_map(fn($col) => DB::escapeIdentifier($col), $idx['columns']));
            
            $operations[] = [
                'type' => 'add_index',
                'index' => $idx['name'],
                'sql' => "CREATE {$type} {$escapedIndexName} ON {$this->table} ({$escapedColumns})",
            ];
        }

        // 删列 / 删索引 / 重命名 —— 下面这些 op 的 'sql' 仅为 toAlterSqls() 兼容保留；
        // Schema::table() 实际执行时委托给 Schema::dropColumn/dropIndex/renameColumn 静态版（更稳）。
        foreach ($this->dropColumns as $c) {
            $operations[] = [
                'type' => 'drop_column',
                'column' => $c,
                'sql' => "ALTER TABLE {$this->table} DROP COLUMN " . DB::escapeIdentifier($c),
            ];
        }

        // 删索引（MySQL 走 ALTER TABLE DROP INDEX；SQLite 是独立 DROP INDEX）
        foreach ($this->dropIndexes as $idxName) {
            $escapedIndexName = DB::escapeIdentifier($idxName);
            $operations[] = [
                'type' => 'drop_index',
                'index' => $idxName,
                'sql' => $driver === 'sqlite'
                    ? "DROP INDEX IF EXISTS {$escapedIndexName}"
                    : "ALTER TABLE {$this->table} DROP INDEX {$escapedIndexName}",
            ];
        }

        // 重命名列（MySQL 8.0+ / SQLite 3.25+ 原生 RENAME COLUMN）
        foreach ($this->renameColumns as $rn) {
            $operations[] = [
                'type' => 'rename_column',
                'from' => $rn['from'],
                'to' => $rn['to'],
                'sql' => "ALTER TABLE {$this->table} RENAME COLUMN "
                    . DB::escapeIdentifier($rn['from']) . " TO " . DB::escapeIdentifier($rn['to']),
            ];
        }

        return $operations;
    }
    
    /**
     * 生成修改表的 SQL 数组（兼容旧调用方式）
     */
    public function toAlterSqls(): array
    {
        return array_column($this->getAlterOperations(), 'sql');
    }
}

/**
 * Column - 列定义构建器
 */
class Column
{
    public array $definition;
    private ?Blueprint $blueprint;
    private string $rawName;
    
    public function __construct(string $name, string $type, ?Blueprint $blueprint = null)
    {
        $this->rawName = $name;
        $this->blueprint = $blueprint;
        $this->definition = [
            'name' => $name,
            'type' => $type,
            'nullable' => false,
        ];
    }
    
    /**
     * 允许为空
     */
    public function nullable(): self
    {
        $this->definition['nullable'] = true;
        return $this;
    }
    
    /**
     * 设置默认值
     */
    public function default($value): self
    {
        if (is_string($value)) {
            // 转义单引号防止 SQL 注入
            $escapedValue = str_replace("'", "''", $value);
            $this->definition['default'] = "'{$escapedValue}'";
        } elseif (is_null($value)) {
            $this->definition['default'] = 'NULL';
        } elseif (is_bool($value)) {
            $this->definition['default'] = $value ? '1' : '0';
        } else {
            $this->definition['default'] = $value;
        }
        return $this;
    }
    
    /**
     * 无符号（MySQL 专用，SQLite 忽略）
     * 
     * 用法: $table->integer('age')->unsigned()
     */
    public function unsigned(): self
    {
        $this->definition['unsigned'] = true;
        return $this;
    }
    
    /**
     * 指定列顺序（MySQL 专用，SQLite 忽略）
     * 
     * 用法: $table->string('nickname')->after('username')
     */
    public function after(string $column): self
    {
        $this->definition['after'] = $column;
        return $this;
    }
    
    /**
     * 唯一约束
     */
    public function unique(): self
    {
        $this->definition['unique'] = true;
        return $this;
    }
    
    /**
     * 注释
     */
    public function comment(string $comment): self
    {
        $this->definition['comment'] = $comment;
        return $this;
    }
    
    /**
     * 添加索引（链式调用，委托给 Blueprint）
     * 
     * 用法: $table->string('user_id')->index()
     *       $table->string('user_id')->index('idx_custom_name')
     */
    public function index(?string $name = null): self
    {
        if ($this->blueprint) {
            $this->blueprint->index($this->rawName, $name);
        }
        return $this;
    }
}
