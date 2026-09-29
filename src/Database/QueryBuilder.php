<?php
/**
 * 查询构建器
 * 
 * 使用示例：
 * DB::table('users')
 *   ->select('id', 'name', 'email')
 *   ->where('age', '>', 18)
 *   ->where('status', 'active')
 *   ->orderBy('created_at', 'desc')
 *   ->limit(10)
 *   ->get();
 */

namespace OmniPHP\Database;

use PDO;
use OmniPHP\Logger;

class QueryBuilder
{
    private PDO $pdo;
    private string $table;
    private array $select = ['*'];
    private array $joins = [];
    private array $where = [];
    private array $bindings = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private array $having = [];
    private array $havingBindings = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;
    private ?string $forceIndex = null;
    private ?string $lastExecutedSql = null;
    
    public function __construct(PDO $pdo, string $table)
    {
        $this->pdo = $pdo;
        $this->table = $this->escapeTableExpr($table);
    }

    /**
     * 使用当前连接的 PDO 转义标识符（表名、列名）
     *
     * 确保使用正确的数据库驱动（MySQL 用反引号，SQLite 用双引号）
     * 避免非默认连接（如 agccam MySQL）错误使用默认 DB 的驱动格式
     */
    private function escapeId(string $identifier): string
    {
        return DB::escapeIdentifier($identifier, $this->pdo);
    }

    /**
     * 转义"表名表达式"——支持 alias 形式
     *
     *   'users'             → `users`
     *   'users as u'        → `users` AS `u`
     *   'users AS u'        → `users` AS `u`
     *
     * 不支持隐式 alias（`users u`）—— 歧义太大，强制显式写 `as`。
     *
     * 历史踩坑：DB::escapeIdentifier 内部 preg_replace 会把空格和 'as' 当 noise
     * 一起剥掉，'users as u' 变成 'usersasu'。如果想用 alias 必须在 escapeId
     * 调用**之前**拆开两段分别 escape，本方法就是这个拆分入口。
     */
    private function escapeTableExpr(string $expr): string
    {
        if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $expr, $m)) {
            return $this->escapeId($m[1]) . ' AS ' . $this->escapeId($m[2]);
        }
        return $this->escapeId($expr);
    }
    
    /**
     * 选择字段（支持 table.column / table.* / col as alias / table.col as alias 全部格式）
     *
     * 用法: ->select('id', 'name')
     *       ->select('users.id', 'users.name', 'books.title')
     *       ->select('orders.*')
     *       ->select('users.id', 'books.title as book_title')   ← alias 形式
     */
    public function select(string ...$columns): self
    {
        $this->select = array_map(fn($col) => $this->escapeColumnExpr($col), $columns);
        return $this;
    }

    /**
     * 转义"列表达式"——支持 alias 形式
     *
     *   '*'                         → *
     *   'name'                      → `name`
     *   'orders.*'                  → `orders`.*
     *   'users.id'                  → `users`.`id`
     *   'users.id as uid'           → `users`.`id` AS `uid`
     *   'name as user_name'         → `name` AS `user_name`
     *
     * 跟 escapeTableExpr 一样的原因：DB::escapeIdentifier 内部 preg_replace 会把
     * 空格和 'as' 当 noise 剥掉，得在调用前先拆。
     */
    private function escapeColumnExpr(string $expr): string
    {
        if ($expr === '*') return '*';

        // 显式 ' as ' 分隔的 alias 形式
        if (preg_match('/^\s*(.+?)\s+as\s+(\S+)\s*$/i', $expr, $m)) {
            $left  = trim($m[1]);
            $alias = $m[2];
            return $this->escapeColumn($left) . ' AS ' . $this->escapeId($alias);
        }

        return $this->escapeColumn($expr);
    }
    
    /**
     * DISTINCT 查询
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * 强制优化器走指定索引（SQL hint）
     *
     * 用法：
     *   DB::table('orders')
     *     ->forceIndex('idx_deleted_attr')
     *     ->where('attr_name', 'LIKE', '%xxx%')
     *     ->whereNull('deleted_at')
     *     ->orderBy(...)
     *     ->get();
     *
     * 何时用：**几乎不用**。优化器一般选得对。**只有 EXPLAIN 实证它选错**
     * 才用 —— 比如它把"避免 filesort"看得太重，选了全索引扫描 ORDER BY 索引，
     * 反而比 ICP 索引慢 10 倍。
     *
     * 已踩坑实例：orders 上 LIKE %x% + ORDER BY config_id 的查询，
     * MySQL 优化器固执选 idx_config_deleted_scope 做全索引扫描（4.27s），而
     * idx_deleted_attr 走 ICP 只要 0.3s。FORCE 之后立刻 10x。
     *
     * Driver 行为：
     *   - MySQL  → 拼成 `FORCE INDEX (idx_name)`
     *   - SQLite → 拼成 `INDEXED BY idx_name`（语义等价）
     *   - PostgreSQL → 没有核心 hint 语法（pg 优化器哲学："应该自己选对"），
     *                  silently 忽略不报错，保持代码可移植
     */
    public function forceIndex(string $indexName): self
    {
        $this->forceIndex = $indexName;
        return $this;
    }

    /**
     * 构造 FROM 之后的索引 hint SQL 片段
     *
     * 各 driver 支持情况：
     *   - MySQL  → `FORCE INDEX (idx_name)`         核心语法
     *   - SQLite → `INDEXED BY idx_name`            核心语法（写法不同但语义等价）
     *   - PostgreSQL → 不支持（优化器哲学："应该自己选对"，需要装 pg_hint_plan
     *                  扩展才行，不是核心 SQL，silently skip）
     */
    private function buildIndexHint(): string
    {
        if (empty($this->forceIndex)) return '';

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $escapedName = $this->escapeId($this->forceIndex);

        return match ($driver) {
            'mysql'  => ' FORCE INDEX (' . $escapedName . ')',
            'sqlite' => ' INDEXED BY ' . $escapedName,
            default  => '',   // pgsql 等无原生 hint 的 driver: silently skip
        };
    }
    
    /**
     * INNER JOIN
     * 
     * 用法: DB::table('orders')
     *         ->join('users', 'orders.user_id', '=', 'users.id')
     *         ->select('orders.*', 'users.username')
     *         ->get();
     */
    public function join(string $table, string $col1, string $operator, string $col2): self
    {
        return $this->addJoin('INNER JOIN', $table, $col1, $operator, $col2);
    }
    
    /**
     * LEFT JOIN
     * 
     * 用法: ->leftJoin('books', 'users.id', '=', 'books.user_id')
     */
    public function leftJoin(string $table, string $col1, string $operator, string $col2): self
    {
        return $this->addJoin('LEFT JOIN', $table, $col1, $operator, $col2);
    }
    
    /**
     * RIGHT JOIN（SQLite 不支持，自动转为 LEFT JOIN 反转）
     */
    public function rightJoin(string $table, string $col1, string $operator, string $col2): self
    {
        return $this->addJoin('RIGHT JOIN', $table, $col1, $operator, $col2);
    }
    
    /**
     * 添加 JOIN 子句
     */
    private function addJoin(string $type, string $table, string $col1, string $operator, string $col2): self
    {
        $operator = $this->validateOperator($operator);

        $this->joins[] = [
            'type' => $type,
            // 用 escapeTableExpr 支持 'table as alias' 形式（leftJoin('groups as g', ...)）
            'table' => $this->escapeTableExpr($table),
            'col1' => $this->escapeJoinColumn($col1),
            'operator' => $operator,
            'col2' => $this->escapeJoinColumn($col2),
        ];

        return $this;
    }
    
    /**
     * 转义 JOIN 中的 table.column 格式
     */
    private function escapeJoinColumn(string $column): string
    {
        return $this->escapeColumn($column);
    }
    
    /**
     * 转义列名（支持 table.column 格式）
     * 
     * 'created_at'              → "created_at"
     * 'api_usage_logs.created_at' → "api_usage_logs"."created_at"
     * 'orders.*'                 → "orders".*
     */
    private function escapeColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            $parts = explode('.', $column, 2);
            return $this->escapeId($parts[0]) . '.' . ($parts[1] === '*' ? '*' : $this->escapeId($parts[1]));
        }
        return $this->escapeId($column);
    }
    
    /**
     * 验证 WHERE 操作符
     */
    private function validateOperator(string $operator): string
    {
        // 白名单：只允许安全的操作符
        $allowedOperators = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];
        
        $operator = strtoupper(trim($operator));
        
        if (!in_array($operator, $allowedOperators)) {
            // 如果不在白名单中，默认使用 =
            $operator = '=';
        }
        
        return $operator;
    }
    
    /**
     * WHERE 条件（支持多种调用方式）
     * 
     * 用法1: ->where('name', 'test')              // name = 'test'
     * 用法2: ->where('age', '>', 18)               // age > 18
     * 用法3: ->where([                             // 批量条件
     *            ['name', 'test'],                  // name = 'test'
     *            ['age', '>', 18],                  // age > 18
     *         ])
     * 用法4: ->where(['name' => 'test', 'status' => 1])  // name = 'test' AND status = 1
     * 用法5: ->where(function($q) {                // 分组条件（括号内 OR/AND）
     *            $q->where('name', 'LIKE', '%test%')
     *              ->orWhere('key', 'LIKE', '%test%');
     *         })                                    // → AND (name LIKE ? OR key LIKE ?)
     */
    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): self
    {
        // 用法5: Closure 分组条件 → AND (...)
        if ($column instanceof \Closure) {
            $sub = new self($this->pdo, $this->table);
            $column($sub);
            if (!empty($sub->where)) {
                $this->where[] = [
                    'type' => 'AND',
                    'nested' => $sub->where,
                ];
            }
            return $this;
        }
        
        // 用法3: 二维数组 [['name', 'test'], ['age', '>', 18]]
        if (is_array($column) && isset($column[0]) && is_array($column[0])) {
            foreach ($column as $condition) {
                if (count($condition) === 2) {
                    $this->where($condition[0], $condition[1]);
                } elseif (count($condition) === 3) {
                    $this->where($condition[0], $condition[1], $condition[2]);
                }
            }
            return $this;
        }
        
        // 用法4: 关联数组 ['name' => 'test', 'status' => 1]
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where($key, $val);
            }
            return $this;
        }
        
        // 用法1/2: 字符串列名
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => $this->validateOperator($operator),
            'value' => $value
        ];
        
        return $this;
    }
    
    /**
     * OR WHERE 条件
     */
    public function orWhere(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'OR',
            'column' => $this->escapeColumn($column),
            'operator' => $this->validateOperator($operator),
            'value' => $value
        ];
        
        return $this;
    }
    
    /**
     * WHERE IN
     */
    public function whereIn(string $column, array $values): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => 'IN',
            'value' => $values
        ];
        
        return $this;
    }
    
    /**
     * WHERE NOT IN
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => 'NOT IN',
            'value' => $values
        ];
        
        return $this;
    }
    
    /**
     * WHERE NULL
     */
    public function whereNull(string $column): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => 'IS NULL',
            'value' => null
        ];
        
        return $this;
    }
    
    /**
     * WHERE NOT NULL
     */
    public function whereNotNull(string $column): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        
        return $this;
    }
    
    /**
     * 原始 WHERE 表达式
     * 
     * 用法: ->whereRaw('YEAR(created_at) = ?', [2026])
     *       ->whereRaw('balance > price * 1.1')
     */
    public function whereRaw(string $expression, array $bindings = []): self
    {
        $this->where[] = [
            'type' => 'AND',
            'raw' => true,
            'expression' => $expression,
            'bindings' => $bindings,
        ];
        
        return $this;
    }
    
    /**
     * WHERE BETWEEN
     * 
     * 用法: ->whereBetween('created_at', '2026-01-01', '2026-12-31')
     */
    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => 'BETWEEN',
            'value' => [$min, $max],
        ];
        
        return $this;
    }
    
    /**
     * WHERE NOT BETWEEN
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): self
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $this->escapeColumn($column),
            'operator' => 'NOT BETWEEN',
            'value' => [$min, $max],
        ];
        
        return $this;
    }
    
    /**
     * WHERE EXISTS (子查询)
     * 
     * 用法: DB::table('users')->whereExists(function($query) {
     *           return DB::table('orders')->whereRaw('orders.user_id = users.id');
     *       })->get();
     * 
     * 或传入 QueryBuilder:
     *       ->whereExists(DB::table('orders')->whereRaw('orders.user_id = users.id'))
     */
    public function whereExists(callable|QueryBuilder $subQuery): self
    {
        $sub = $this->resolveSubQuery($subQuery);
        
        $this->where[] = [
            'type' => 'AND',
            'raw' => true,
            'expression' => "EXISTS ({$sub['sql']})",
            'bindings' => $sub['bindings'],
        ];
        
        return $this;
    }
    
    /**
     * WHERE NOT EXISTS (子查询)
     */
    public function whereNotExists(callable|QueryBuilder $subQuery): self
    {
        $sub = $this->resolveSubQuery($subQuery);
        
        $this->where[] = [
            'type' => 'AND',
            'raw' => true,
            'expression' => "NOT EXISTS ({$sub['sql']})",
            'bindings' => $sub['bindings'],
        ];
        
        return $this;
    }
    
    /**
     * WHERE column IN (子查询)
     * 
     * 用法: DB::table('users')->whereInSub('id', function($q) {
     *           return DB::table('orders')->select('user_id')->where('status', 'paid');
     *       })->get();
     * 
     * 或: ->whereInSub('id', DB::table('orders')->select('user_id')->where('status', 'paid'))
     */
    public function whereInSub(string $column, callable|QueryBuilder $subQuery): self
    {
        $sub = $this->resolveSubQuery($subQuery);
        
        $this->where[] = [
            'type' => 'AND',
            'raw' => true,
            'expression' => $this->escapeId($column) . " IN ({$sub['sql']})",
            'bindings' => $sub['bindings'],
        ];
        
        return $this;
    }
    
    /**
     * WHERE column NOT IN (子查询)
     */
    public function whereNotInSub(string $column, callable|QueryBuilder $subQuery): self
    {
        $sub = $this->resolveSubQuery($subQuery);
        
        $this->where[] = [
            'type' => 'AND',
            'raw' => true,
            'expression' => $this->escapeId($column) . " NOT IN ({$sub['sql']})",
            'bindings' => $sub['bindings'],
        ];
        
        return $this;
    }
    
    /**
     * WHERE column operator (子查询)
     * 
     * 用法: ->whereSub('score', '>', DB::table('users')->selectRaw('AVG(score)'))
     */
    public function whereSub(string $column, string $operator, callable|QueryBuilder $subQuery): self
    {
        $sub = $this->resolveSubQuery($subQuery);
        $operator = $this->validateOperator($operator);
        
        $this->where[] = [
            'type' => 'AND',
            'raw' => true,
            'expression' => $this->escapeId($column) . " {$operator} ({$sub['sql']})",
            'bindings' => $sub['bindings'],
        ];
        
        return $this;
    }
    
    /**
     * SELECT 子查询（作为列）
     * 
     * 用法: DB::table('users')->selectSub(
     *           DB::table('orders')->selectRaw('COUNT(*)')->whereRaw('orders.user_id = users.id'),
     *           'order_count'
     *       )->get();
     * 结果: SELECT *, (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) AS order_count FROM users
     */
    public function selectSub(callable|QueryBuilder $subQuery, string $alias): self
    {
        $sub = $this->resolveSubQuery($subQuery);
        
        // 如果 select 还是默认的 ['*']，保留它；否则追加
        $subSelect = "({$sub['sql']}) AS " . $this->escapeId($alias);
        
        if ($this->select === ['*']) {
            $this->select = ['*', $subSelect];
        } else {
            $this->select[] = $subSelect;
        }
        
        // 子查询的 bindings 需要在最前面（SELECT 部分先于 WHERE 绑定）
        // 但 PDO 是按顺序的，所以需要前置
        $this->bindings = array_merge($sub['bindings'], $this->bindings);
        
        return $this;
    }
    
    /**
     * 解析子查询（支持闭包和 QueryBuilder）
     * 返回 ['sql' => '...', 'bindings' => [...]]
     */
    private function resolveSubQuery(callable|QueryBuilder $subQuery): array
    {
        if ($subQuery instanceof QueryBuilder) {
            $builder = $subQuery;
        } else {
            $builder = $subQuery();
            if (!$builder instanceof QueryBuilder) {
                throw new \InvalidArgumentException('Subquery callback must return a QueryBuilder instance');
            }
        }
        
        // 构建子查询 SQL 和 bindings
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();
        
        return ['sql' => $sql, 'bindings' => $bindings];
    }
    
    /**
     * GROUP BY
     * 
     * 用法: ->groupBy('status') 或 ->groupBy('year', 'month')
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $col) {
            $this->groupBy[] = $this->escapeColumn($col);
        }
        return $this;
    }
    
    /**
     * HAVING 条件（配合 GROUP BY 使用）
     * 
     * 用法: ->having('count', '>', 5)
     */
    public function having(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->having[] = [
            'column' => $this->escapeColumn($column),
            'operator' => $this->validateOperator($operator),
            'value' => $value,
        ];
        
        return $this;
    }
    
    /**
     * 排序（支持 table.column 格式）
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        // 验证排序方向，防止注入
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }
        
        $this->orderBy[] = [$this->escapeColumn($column), $direction];
        return $this;
    }

    /**
     * 原始 ORDER BY 表达式（对齐 Laravel orderByRaw）。
     *
     * ⚠️ 表达式直接拼入 SQL **不转义** —— 只传可信常量表达式（如 'RAND()' / 'FIELD(id,1,2)'），
     *    禁止拼接用户输入，否则 SQL 注入。
     */
    public function orderByRaw(string $expression): self
    {
        $this->orderBy[] = [$expression, ''];   // 空 direction → buildOrderBySql 只输出表达式本身
        return $this;
    }

    /**
     * 随机排序（对齐 Laravel inRandomOrder）。MySQL→RAND()，SQLite→RANDOM()。
     */
    public function inRandomOrder(): self
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return $this->orderByRaw($driver === 'mysql' ? 'RAND()' : 'RANDOM()');
    }

    /**
     * 限制数量
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * 偏移量
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * 执行查询（自动处理连接丢失 + 查询日志 + 慢查询检测）
     * 
     * 事务中不自动重连（重连会丢失事务状态，必须让上层重试整个事务）
     */
    private function executeQuery(callable $callback): mixed
    {
        $start = microtime(true);
        
        try {
            $result = $callback();
            
            // 记录查询日志
            $time = microtime(true) - $start;
            DB::logQuery($this->lastExecutedSql ?? $this->buildSelectSql(), $this->bindings, $time);
            
            return $result;
        } catch (\PDOException $e) {
            // 事务中不自动重连；非默认连接也跳过（DB::reconnect 只处理默认库）
            $isDefaultDb = DB::isInitialized() && $this->pdo === DB::connection();
            if ($isDefaultDb && !DB::inTransaction() && DB::isConnectionError($e)) {
                Logger::warning("[DB] QueryBuilder: connection lost, reconnecting...");
                DB::reconnect();
                // 更新 PDO 实例
                $this->pdo = DB::connection();
                // 重试查询
                $start = microtime(true);
                $result = $callback();
                
                $time = microtime(true) - $start;
                DB::logQuery($this->lastExecutedSql ?? 'RECONNECT + RETRY', $this->bindings, $time);
                
                return $result;
            }
            // 记录失败查询
            $time = microtime(true) - $start;
            DB::logQuery('FAILED: ' . ($this->lastExecutedSql ?? $e->getMessage()), $this->bindings, $time);
            
            throw $e;
        }
    }
    
    /**
     * 获取所有结果
     */
    public function get(): array
    {
        return $this->executeQuery(function() {
            $sql = $this->buildSelectSql();
            $this->lastExecutedSql = $sql;
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            return $stmt->fetchAll();
        });
    }
    
    /**
     * 获取第一条结果
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }
    
    /**
     * 根据 ID 查找
     */
    public function find(int|string $id): ?array
    {
        return $this->where('id', $id)->first();
    }
    
    /**
     * 获取单个值
     */
    public function value(string $column): mixed
    {
        $result = $this->select($column)->first();
        return $result[$column] ?? null;
    }
    
    /**
     * 计数
     * 
     * 支持 DISTINCT：
     *   DB::table('users')->count()                              → SELECT COUNT(*) ...
     *   DB::table('logs')->distinct()->select('user_id')->count() → SELECT COUNT(DISTINCT "user_id") ...
     */
    public function count(): int
    {
        return $this->executeQuery(function() {
            // 如果设置了 DISTINCT 且指定了具体列（非 *），使用 COUNT(DISTINCT col)
            if ($this->distinct && $this->select !== ['*'] && count($this->select) === 1) {
                $col = $this->select[0];
                $countExpr = "COUNT(DISTINCT {$col})";
            } elseif ($this->distinct && $this->select !== ['*'] && count($this->select) > 1) {
                // 多列 DISTINCT: COUNT(DISTINCT col1, col2) — SQLite 不支持，用子查询
                $distinct = 'DISTINCT ' . implode(', ', $this->select);
                $innerSql = "SELECT {$distinct} FROM {$this->table}";
                $innerSql .= $this->buildJoinSql();
                $innerSql .= $this->buildWhereSql();
                $innerSql .= $this->buildGroupBySql();
                $innerSql .= $this->buildHavingSql();
                
                $sql = "SELECT COUNT(*) as count FROM ({$innerSql}) AS _distinct_count";
                
                $allBindings = array_merge($this->bindings, $this->havingBindings);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($allBindings);
                $result = $stmt->fetch();
                return (int) $result['count'];
            } else {
                $countExpr = "COUNT(*)";
            }
            
            $sql = "SELECT {$countExpr} as count FROM {$this->table}";
            $sql .= $this->buildJoinSql();
            $sql .= $this->buildWhereSql();
            $sql .= $this->buildGroupBySql();
            $sql .= $this->buildHavingSql();
            
            $allBindings = array_merge($this->bindings, $this->havingBindings);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($allBindings);
            $result = $stmt->fetch();
            
            return (int) $result['count'];
        });
    }
    
    /**
     * 是否存在
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }
    
    /**
     * 求和
     * 
     * 用法: DB::table('orders')->where('status', 'paid')->sum('amount')
     */
    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }
    
    /**
     * 最大值
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }
    
    /**
     * 最小值
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }
    
    /**
     * 平均值
     */
    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }
    
    /**
     * 通用聚合查询
     */
    private function aggregate(string $function, string $column): mixed
    {
        return $this->executeQuery(function() use ($function, $column) {
            $escapedColumn = $this->escapeId($column);
            $sql = "SELECT {$function}({$escapedColumn}) as aggregate FROM {$this->table}";
            $sql .= $this->buildWhereSql();
            $sql .= $this->buildGroupBySql();
            $sql .= $this->buildHavingSql();
            
            $allBindings = array_merge($this->bindings, $this->havingBindings);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($allBindings);
            $result = $stmt->fetch();
            
            return $result['aggregate'] ?? null;
        });
    }
    
    /**
     * 获取某一列的值组成数组
     * 
     * 用法: DB::table('users')->pluck('email')         → ['a@test.com', 'b@test.com']
     *       DB::table('users')->pluck('email', 'id')    → [1 => 'a@test.com', 2 => 'b@test.com']
     */
    public function pluck(string $column, ?string $key = null): array
    {
        if ($key) {
            $this->select = [$this->escapeId($column), $this->escapeId($key)];
        } else {
            $this->select = [$this->escapeId($column)];
        }
        
        $results = $this->get();
        
        if ($key) {
            $plucked = [];
            foreach ($results as $row) {
                $plucked[$row[$key]] = $row[$column];
            }
            return $plucked;
        }
        
        return array_column($results, $column);
    }
    
    /**
     * 分页查询
     * 
     * 用法: DB::table('users')->where('status', 1)->paginate(1, 15)
     * 返回: ['data' => [...], 'total' => 100, 'page' => 1, 'page_size' => 15, 'total_pages' => 7]
     */
    public function paginate(int $page = 1, int $pageSize = 15): array
    {
        $page = max(1, $page);
        
        // 计算总数（需要克隆 where 条件，不能被后续 limit/offset 污染）
        $total = $this->count();
        
        // 重置 bindings（count 已经消费了 bindings）
        $this->bindings = [];
        
        $totalPages = max(1, (int) ceil($total / $pageSize));
        
        $this->limit($pageSize)->offset(($page - 1) * $pageSize);
        $data = $this->get();
        
        return [
            'data'        => $data,
            'total'       => $total,
            'page'        => $page,
            'page_size'   => $pageSize,
            'total_pages' => $totalPages,
        ];
    }
    
    /**
     * 分块处理（适合大数据量，每次只加载一批到内存）
     * 
     * 用法: DB::table('logs')->where('date', '>', '2026-01-01')->chunk(1000, function($rows) {
     *           foreach ($rows as $row) { // 处理每条记录 }
     *       });
     * 
     * @param int $size 每批数量
     * @param callable $callback 回调函数，接收当前批次数据。返回 false 停止遍历
     */
    public function chunk(int $size, callable $callback): bool
    {
        $page = 1;
        
        do {
            // 克隆 where 条件（避免 bindings 被消费后为空）
            $clonedWhere = $this->where;
            $clonedJoins = $this->joins;
            
            $this->bindings = [];
            $this->where = $clonedWhere;
            $this->joins = $clonedJoins;
            
            $this->limit($size)->offset(($page - 1) * $size);
            $results = $this->get();
            
            $count = count($results);
            
            if ($count === 0) {
                break;
            }
            
            if ($callback($results) === false) {
                return false;
            }
            
            $page++;
        } while ($count === $size);
        
        return true;
    }
    
    /**
     * 按主键 ID 分块（避免 OFFSET 大了变慢的问题）
     * 
     * 用法: DB::table('users')->chunkById(1000, function($rows) {
     *           foreach ($rows as $row) { // 处理每条记录 }
     *       });
     * 
     * 原理: WHERE id > 上一批最后ID LIMIT size（比 OFFSET 快很多）
     */
    public function chunkById(int $size, callable $callback, string $column = 'id'): bool
    {
        $lastId = 0;
        
        do {
            // 重置 bindings
            $clonedWhere = $this->where;
            $clonedJoins = $this->joins;
            
            $this->bindings = [];
            $this->where = $clonedWhere;
            $this->joins = $clonedJoins;
            
            // 添加 ID 条件
            $this->where($column, '>', $lastId);
            $this->orderBy($column, 'ASC');
            $this->limit($size);
            $this->offset = null;
            
            $results = $this->get();
            $count = count($results);
            
            if ($count === 0) {
                break;
            }
            
            if ($callback($results) === false) {
                return false;
            }
            
            // 记录最后一条的 ID
            $lastId = $results[$count - 1][$column];
            
            // 移除本轮添加的 where 和 orderBy（保留原始条件）
            array_pop($this->where);
            array_pop($this->orderBy);
        } while ($count === $size);
        
        return true;
    }
    
    /**
     * 游标查询（Generator，每次只有一行在内存中，最省内存）
     * 
     * 用法: foreach (DB::table('logs')->cursor() as $row) {
     *           // 一次只有一行在内存中
     *       }
     * 
     * @return \Generator
     */
    public function cursor(): \Generator
    {
        return $this->executeQuery(function() {
            $sql = $this->buildSelectSql();
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            
            while ($row = $stmt->fetch()) {
                yield $row;
            }
        });
    }
    
    /**
     * 批量插入（分批执行，避免 SQL 过长或参数过多）
     * 
     * 用法: DB::table('logs')->insertBatch($thousandsOfRows, 500);
     * 
     * @param array $rows 二维数组，每个元素是一行数据
     * @param int $batchSize 每批插入条数，默认 500
     * @return int 插入总行数
     */
    public function insertBatch(array $rows, int $batchSize = 500): int
    {
        if (empty($rows)) {
            return 0;
        }
        
        $totalInserted = 0;
        $batches = array_chunk($rows, $batchSize);
        
        foreach ($batches as $batch) {
            $totalInserted += $this->executeQuery(function() use ($batch) {
                $columns = array_keys($batch[0]);
                $escapedColumns = implode(', ', array_map(fn($col) => $this->escapeId($col), $columns));
                $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $placeholders = implode(', ', array_fill(0, count($batch), $placeholderRow));
                
                $sql = "INSERT INTO {$this->table} ({$escapedColumns}) VALUES {$placeholders}";
                
                $bindings = [];
                foreach ($batch as $row) {
                    foreach ($columns as $col) {
                        $bindings[] = $row[$col] ?? null;
                    }
                }
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);
                return $stmt->rowCount();
            });
        }
        
        return $totalInserted;
    }
    
    /**
     * 批量更新（按主键逐条更新，分批执行）
     * 
     * 用法: DB::table('users')->updateBatch([
     *          ['id' => 1, 'status' => 'vip', 'score' => 100],
     *          ['id' => 2, 'status' => 'normal', 'score' => 50],
     *       ], 'id');
     * 
     * @param array $rows 二维数组，每行必须包含主键
     * @param string $keyColumn 主键列名
     * @param int $batchSize 每批条数
     * @return int 更新总行数
     */
    public function updateBatch(array $rows, string $keyColumn = 'id', int $batchSize = 500): int
    {
        if (empty($rows)) {
            return 0;
        }
        
        $totalUpdated = 0;
        $batches = array_chunk($rows, $batchSize);
        
        foreach ($batches as $batch) {
            // 使用事务包裹每批更新
            DB::beginTransaction();
            try {
                foreach ($batch as $row) {
                    if (!isset($row[$keyColumn])) {
                        continue;
                    }
                    
                    $keyValue = $row[$keyColumn];
                    $updateData = $row;
                    unset($updateData[$keyColumn]);
                    
                    if (empty($updateData)) {
                        continue;
                    }
                    
                    $sets = [];
                    $bindings = [];
                    foreach ($updateData as $col => $val) {
                        $sets[] = $this->escapeId($col) . ' = ?';
                        $bindings[] = $val;
                    }
                    $bindings[] = $keyValue;
                    
                    $sql = "UPDATE {$this->table} SET " . implode(', ', $sets)
                         . " WHERE " . $this->escapeId($keyColumn) . " = ?";
                    
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($bindings);
                    $totalUpdated += $stmt->rowCount();
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
        
        return $totalUpdated;
    }
    
    /**
     * 批量 Upsert（存在则更新，不存在则插入）
     * 
     * 用法: DB::table('users')->upsertBatch([
     *          ['email' => 'a@test.com', 'name' => 'A', 'score' => 100],
     *          ['email' => 'b@test.com', 'name' => 'B', 'score' => 50],
     *       ], 'email', ['name', 'score']);
     * 
     * @param array $rows 要插入/更新的数据
     * @param string|array $uniqueColumns 唯一键列（用于判断冲突）
     * @param array $updateColumns 冲突时要更新的列（为空则更新所有非唯一列）
     * @param int $batchSize 每批条数
     * @return int 影响行数
     */
    public function upsertBatch(array $rows, string|array $uniqueColumns, array $updateColumns = [], int $batchSize = 500): int
    {
        if (empty($rows)) {
            return 0;
        }
        
        $uniqueColumns = is_array($uniqueColumns) ? $uniqueColumns : [$uniqueColumns];
        $columns = array_keys($rows[0]);
        
        // 如果没指定更新列，则更新除唯一键外的所有列
        if (empty($updateColumns)) {
            $updateColumns = array_diff($columns, $uniqueColumns);
        }
        
        $pdo = $this->pdo;
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        $totalAffected = 0;
        $batches = array_chunk($rows, $batchSize);
        
        foreach ($batches as $batch) {
            $totalAffected += $this->executeQuery(function() use ($batch, $columns, $uniqueColumns, $updateColumns, $driver) {
                $escapedColumns = implode(', ', array_map(fn($c) => $this->escapeId($c), $columns));
                $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $placeholders = implode(', ', array_fill(0, count($batch), $placeholderRow));
                
                // 构建 ON CONFLICT / ON DUPLICATE KEY 子句
                $updateSets = implode(', ', array_map(function($col) use ($driver) {
                    $escaped = $this->escapeId($col);
                    if ($driver === 'sqlite') {
                        return "{$escaped} = excluded.{$escaped}";
                    } else {
                        return "{$escaped} = VALUES({$escaped})";
                    }
                }, $updateColumns));
                
                if ($driver === 'sqlite') {
                    $uniqueEscaped = implode(', ', array_map(fn($c) => $this->escapeId($c), $uniqueColumns));
                    $sql = "INSERT INTO {$this->table} ({$escapedColumns}) VALUES {$placeholders}"
                         . " ON CONFLICT ({$uniqueEscaped}) DO UPDATE SET {$updateSets}";
                } else {
                    $sql = "INSERT INTO {$this->table} ({$escapedColumns}) VALUES {$placeholders}"
                         . " ON DUPLICATE KEY UPDATE {$updateSets}";
                }
                
                $bindings = [];
                foreach ($batch as $row) {
                    foreach ($columns as $col) {
                        $bindings[] = $row[$col] ?? null;
                    }
                }
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);
                return $stmt->rowCount();
            });
        }
        
        return $totalAffected;
    }
    
    /**
     * 原始 SELECT 表达式（用于聚合函数等）
     * 
     * 用法: ->selectRaw('COUNT(*) as cnt, status')->groupBy('status')
     */
    public function selectRaw(string $expression): self
    {
        $this->select = [$expression];
        return $this;
    }
    
    /**
     * 插入数据
     */
    public function insert(array $data): bool
    {
        return $this->executeQuery(function() use ($data) {
            $columns = implode(', ', array_map(fn($col) => $this->escapeId($col), array_keys($data)));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            
            $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
            $this->lastExecutedSql = $sql;
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(array_values($data));
        });
    }
    
    /**
     * 插入并返回 ID
     */
    public function insertGetId(array $data): string
    {
        $this->insert($data);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * 更新数据
     */
    public function update(array $data): int
    {
        return $this->executeQuery(function() use ($data) {
            $sets = [];
            $bindings = [];
            
            foreach ($data as $column => $value) {
                // 转义列名防止注入
                $sets[] = $this->escapeId($column) . " = ?";
                $bindings[] = $value;
            }
            
            $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
            $sql .= $this->buildWhereSql();
            $this->lastExecutedSql = $sql;
            
            $bindings = array_merge($bindings, $this->bindings);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            
            return $stmt->rowCount();
        });
    }
    
    /**
     * 删除数据
     */
    public function delete(): int
    {
        return $this->executeQuery(function() {
            $sql = "DELETE FROM {$this->table}";
            $sql .= $this->buildWhereSql();
            $this->lastExecutedSql = $sql;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            
            return $stmt->rowCount();
        });
    }
    
    /**
     * 清空表（TRUNCATE）
     * 
     * 注意：TRUNCATE 会：
     * 1. 清空表中所有数据
     * 2. 重置自增ID
     * 3. 不能使用 WHERE 条件
     * 4. 不能回滚（在某些数据库中）
     */
    public function truncate(): bool
    {
        return $this->executeQuery(function() {
            // SQLite 不支持 TRUNCATE，使用 DELETE + 重置自增序列
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            
            if ($driver === 'sqlite') {
                // SQLite: 删除所有数据并重置自增
                // 注意：$this->table 已在构造函数中转义
                $stmt1 = $this->pdo->prepare("DELETE FROM {$this->table}");
                $stmt1->execute();
                
                // 从 sqlite_sequence 表中删除自增记录
                // 需要去掉引号来匹配 sqlite_sequence 中的表名
                $tableName = trim($this->table, '"');
                $stmt2 = $this->pdo->prepare("DELETE FROM sqlite_sequence WHERE name = ?");
                $stmt2->execute([$tableName]);
                
                return true;
            } else {
                // MySQL/PostgreSQL: 使用 TRUNCATE
                $stmt = $this->pdo->prepare("TRUNCATE TABLE {$this->table}");
                return $stmt->execute();
            }
        });
    }
    
    /**
     * 自增
     */
    public function increment(string $column, int $amount = 1): int
    {
        return $this->executeQuery(function() use ($column, $amount) {
            // 转义列名防止注入
            $escapedColumn = $this->escapeId($column);
            $sql = "UPDATE {$this->table} SET {$escapedColumn} = {$escapedColumn} + ?";
            $sql .= $this->buildWhereSql();
            
            $bindings = array_merge([$amount], $this->bindings);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            
            return $stmt->rowCount();
        });
    }
    
    /**
     * 自减
     */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->increment($column, -$amount);
    }
    
    /**
     * 构建 SELECT SQL
     */
    private function buildSelectSql(): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $sql = "SELECT {$distinct}" . implode(', ', $this->select) . " FROM {$this->table}";
        $sql .= $this->buildIndexHint();   // FORCE INDEX 紧跟在 FROM 之后（仅 MySQL）
        $sql .= $this->buildJoinSql();
        $sql .= $this->buildWhereSql();
        $sql .= $this->buildGroupBySql();
        $sql .= $this->buildHavingSql();
        $sql .= $this->buildOrderBySql();
        $sql .= $this->buildLimitSql();
        
        return $sql;
    }
    
    /**
     * 构建 JOIN SQL
     */
    private function buildJoinSql(): string
    {
        if (empty($this->joins)) {
            return '';
        }
        
        $sql = '';
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} {$join['table']} ON {$join['col1']} {$join['operator']} {$join['col2']}";
        }
        
        return $sql;
    }
    
    /**
     * 构建 WHERE SQL
     */
    private function buildWhereSql(): string
    {
        if (empty($this->where)) {
            $this->bindings = [];
            return '';
        }
        
        // 每次构建 WHERE 时重置 bindings，避免重连重试时重复追加
        $this->bindings = [];
        $conditions = [];
        
        foreach ($this->where as $i => $condition) {
            $type = $i === 0 ? 'WHERE' : $condition['type'];
            
            // 嵌套分组条件 → AND/OR (col1 = ? OR col2 = ?)
            if (!empty($condition['nested'])) {
                $subParts = [];
                foreach ($condition['nested'] as $j => $sub) {
                    $subType = $j === 0 ? '' : $sub['type'] . ' ';
                    
                    if (!empty($sub['raw'])) {
                        $subParts[] = $subType . $sub['expression'];
                        $this->bindings = array_merge($this->bindings, $sub['bindings'] ?? []);
                    } elseif ($sub['operator'] === 'IN' || $sub['operator'] === 'NOT IN') {
                        $placeholders = implode(', ', array_fill(0, count($sub['value']), '?'));
                        $subParts[] = "{$subType}{$sub['column']} {$sub['operator']} ({$placeholders})";
                        $this->bindings = array_merge($this->bindings, $sub['value']);
                    } elseif ($sub['operator'] === 'IS NULL' || $sub['operator'] === 'IS NOT NULL') {
                        $subParts[] = "{$subType}{$sub['column']} {$sub['operator']}";
                    } elseif ($sub['operator'] === 'BETWEEN' || $sub['operator'] === 'NOT BETWEEN') {
                        $subParts[] = "{$subType}{$sub['column']} {$sub['operator']} ? AND ?";
                        $this->bindings[] = $sub['value'][0];
                        $this->bindings[] = $sub['value'][1];
                    } else {
                        $subParts[] = "{$subType}{$sub['column']} {$sub['operator']} ?";
                        $this->bindings[] = $sub['value'];
                    }
                }
                $conditions[] = "{$type} (" . implode(' ', $subParts) . ")";
                continue;
            }
            
            // 原始表达式
            if (!empty($condition['raw'])) {
                $conditions[] = "{$type} {$condition['expression']}";
                $this->bindings = array_merge($this->bindings, $condition['bindings'] ?? []);
                continue;
            }
            
            if ($condition['operator'] === 'IN' || $condition['operator'] === 'NOT IN') {
                $placeholders = implode(', ', array_fill(0, count($condition['value']), '?'));
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']} ({$placeholders})";
                $this->bindings = array_merge($this->bindings, $condition['value']);
            } elseif ($condition['operator'] === 'BETWEEN' || $condition['operator'] === 'NOT BETWEEN') {
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']} ? AND ?";
                $this->bindings[] = $condition['value'][0];
                $this->bindings[] = $condition['value'][1];
            } elseif ($condition['operator'] === 'IS NULL' || $condition['operator'] === 'IS NOT NULL') {
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']}";
            } else {
                $conditions[] = "{$type} {$condition['column']} {$condition['operator']} ?";
                $this->bindings[] = $condition['value'];
            }
        }
        
        return ' ' . implode(' ', $conditions);
    }
    
    /**
     * 构建 ORDER BY SQL
     */
    private function buildOrderBySql(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }
        
        // 空 direction（orderByRaw 写入的原始表达式）只输出表达式本身，不补方向
        $orders = array_map(
            fn($order) => trim((string)$order[1]) === '' ? $order[0] : "{$order[0]} {$order[1]}",
            $this->orderBy
        );
        return ' ORDER BY ' . implode(', ', $orders);
    }
    
    /**
     * 构建 GROUP BY SQL
     */
    private function buildGroupBySql(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }
        
        return ' GROUP BY ' . implode(', ', $this->groupBy);
    }
    
    /**
     * 构建 HAVING SQL
     */
    private function buildHavingSql(): string
    {
        if (empty($this->having)) {
            return '';
        }
        
        $conditions = [];
        
        foreach ($this->having as $i => $condition) {
            $prefix = $i === 0 ? 'HAVING' : 'AND';
            $conditions[] = "{$prefix} {$condition['column']} {$condition['operator']} ?";
            $this->havingBindings[] = $condition['value'];
        }
        
        // 将 having bindings 合并到主 bindings 中
        $this->bindings = array_merge($this->bindings, $this->havingBindings);
        $this->havingBindings = [];
        
        return ' ' . implode(' ', $conditions);
    }
    
    /**
     * 构建 LIMIT SQL
     */
    private function buildLimitSql(): string
    {
        $sql = '';
        
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }
        
        return $sql;
    }
    
    /**
     * 获取完整的 SQL（用于调试）
     */
    public function toSql(): string
    {
        return $this->buildSelectSql();
    }
    
    /**
     * 魔术方法：转换为字符串
     * 
     * 使得查询对象可以直接当字符串使用
     */
    public function __toString(): string
    {
        return $this->toSql();
    }
    
    /**
     * 获取绑定参数（用于调试）
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}
