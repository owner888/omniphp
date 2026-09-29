<?php
/**
 * Model 基类（Laravel 风格）
 * 
 * 提供简单的数据库操作：
 * - 查询构建器
 * - CRUD 操作
 * - 关联关系
 */

namespace OmniPHP;

use OmniPHP\Database\QueryBuilder;
use OmniPHP\Database\DB;

abstract class Model
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];
    
    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;
    
    /**
     * 构造函数
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }
    
    /**
     * 获取表名
     */
    public function getTable(): string
    {
        if ($this->table) {
            return $this->table;
        }
        
        // 自动推断表名（类名的复数形式）
        $class = basename(str_replace('\\', '/', get_class($this)));
        return strtolower($class) . 's';
    }
    
    /**
     * 填充属性
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }
    
    /**
     * 检查字段是否可填充
     */
    protected function isFillable(string $key): bool
    {
        if (empty($this->fillable)) {
            return true;
        }
        return in_array($key, $this->fillable);
    }
    
    /**
     * 设置属性
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }
    
    /**
     * 获取属性
     */
    public function getAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }
        return null;
    }
    
    /**
     * 类型转换
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }
        
        $cast = $this->casts[$key];
        
        return match($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }
    
    /**
     * 魔术方法 - 获取属性
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }
    
    /**
     * 魔术方法 - 设置属性
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }
    
    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        $array = $this->attributes;
        
        // 移除隐藏字段
        foreach ($this->hidden as $key) {
            unset($array[$key]);
        }
        
        // 应用类型转换
        foreach ($array as $key => $value) {
            $array[$key] = $this->castAttribute($key, $value);
        }
        
        return $array;
    }
    
    /**
     * 转换为 JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 创建查询构建器
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        return DB::table($instance->getTable());
    }
    
    /**
     * 查询所有记录
     */
    public static function all(): array
    {
        return static::query()->get();
    }
    
    /**
     * 根据主键查询
     */
    public static function find(int|string $id): ?static
    {
        $instance = new static();
        $data = static::query()
            ->where($instance->primaryKey, '=', $id)
            ->first();
        
        if ($data) {
            $model = new static($data);
            $model->exists = true;
            $model->original = $data;
            return $model;
        }
        
        return null;
    }
    
    /**
     * 根据条件查询（支持多种格式，与 QueryBuilder 对齐）
     * 
     * 用法1: User::where('name', 'test')
     * 用法2: User::where('age', '>', 18)
     * 用法3: User::where([['status', 1], ['role', 'vip']])
     * 用法4: User::where(['status' => 1, 'role' => 'vip'])
     */
    public static function where(string|array $column, mixed $operator = null, mixed $value = null): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }
    
    /**
     * 按主键批量删除
     * 
     * 用法: User::destroy(1)
     *       User::destroy(1, 2, 3)
     *       User::destroy([1, 2, 3])
     * 
     * @return int 删除的行数
     */
    public static function destroy(int|string|array ...$ids): int
    {
        // 展平参数：支持 destroy(1,2,3) 和 destroy([1,2,3])
        $flatIds = [];
        foreach ($ids as $id) {
            if (is_array($id)) {
                $flatIds = array_merge($flatIds, $id);
            } else {
                $flatIds[] = $id;
            }
        }
        
        if (empty($flatIds)) {
            return 0;
        }
        
        $instance = new static();
        return static::query()
            ->whereIn($instance->primaryKey, $flatIds)
            ->delete();
    }
    
    /**
     * 创建记录
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }
    
    /**
     * 查找或创建（存在则更新，不存在则新建）
     * 
     * 用法: User::updateOrCreate(
     *          ['email' => 'test@test.com'],           // 查找条件
     *          ['username' => 'test', 'status' => 1]   // 要更新/创建的数据
     *       )
     */
    public static function updateOrCreate(array $conditions, array $values = []): static
    {
        $instance = new static();
        $query = static::query();
        
        foreach ($conditions as $key => $val) {
            $query->where($key, $val);
        }
        
        $data = $query->first();
        
        if ($data) {
            // 存在 → 更新
            $model = static::fromArray($data);
            $model->fill($values);
            $model->save();
            return $model;
        } else {
            // 不存在 → 创建
            return static::create(array_merge($conditions, $values));
        }
    }
    
    /**
     * 查找，找不到则创建（不更新已有记录）
     */
    public static function firstOrCreate(array $conditions, array $values = []): static
    {
        $query = static::query();
        foreach ($conditions as $key => $val) {
            $query->where($key, $val);
        }
        
        $data = $query->first();
        
        if ($data) {
            return static::fromArray($data);
        }
        
        return static::create(array_merge($conditions, $values));
    }
    
    /**
     * 分页查询
     * 
     * 用法: User::where('status', 1)->paginate(1, 15)
     * 注意: 此方法需要在 QueryBuilder 上调用，Model 提供的是快捷入口
     * 
     * 用法: User::paginate(1, 15)
     * 返回: ['data' => [User, User, ...], 'total' => 100, 'page' => 1, ...]
     */
    public static function paginate(int $page = 1, int $pageSize = 15): array
    {
        $result = static::query()->paginate($page, $pageSize);
        
        // 将 data 中的数组转为 Model 实例
        $result['data'] = array_map(fn($row) => static::fromArray($row), $result['data']);
        
        return $result;
    }
    
    /**
     * 批量更新
     * 
     * 用法: User::whereUpdate(['status' => 1], ['role' => 'vip'])
     */
    public static function whereUpdate(array $conditions, array $values): int
    {
        $query = static::query();
        foreach ($conditions as $key => $val) {
            $query->where($key, $val);
        }
        return $query->update($values);
    }
    
    /**
     * 批量删除
     * 
     * 用法: User::whereDelete(['status' => 0])
     */
    public static function whereDelete(array $conditions): int
    {
        $query = static::query();
        foreach ($conditions as $key => $val) {
            $query->where($key, $val);
        }
        return $query->delete();
    }
    
    /**
     * 保存模型
     */
    public function save(): bool
    {
        if ($this->exists) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }
    
    /**
     * 插入新记录
     */
    protected function insert(): bool
    {
        $id = DB::table($this->getTable())->insertGetId($this->attributes);
        
        if ($id) {
            $this->setAttribute($this->primaryKey, (int)$id);
            $this->exists = true;
            $this->original = $this->attributes;
            return true;
        }
        
        return false;
    }
    
    /**
     * 更新记录（只更新变化的字段）
     */
    protected function update(): bool
    {
        $id = $this->getAttribute($this->primaryKey);
        
        // 只更新变化的字段
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if ($key === $this->primaryKey) continue;
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        
        if (empty($dirty)) {
            return true; // 没有变化
        }
        
        $affected = DB::table($this->getTable())
            ->where($this->primaryKey, '=', $id)
            ->update($dirty);
        
        if ($affected >= 0) {
            $this->original = $this->attributes;
            return true;
        }
        
        return false;
    }
    
    /**
     * 删除记录
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        
        $id = $this->getAttribute($this->primaryKey);
        
        $affected = DB::table($this->getTable())
            ->where($this->primaryKey, '=', $id)
            ->delete();
        
        if ($affected > 0) {
            $this->exists = false;
            return true;
        }
        
        return false;
    }
    
    /**
     * 根据主键查找，找不到抛异常
     */
    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);
        if (!$model) {
            $class = basename(str_replace('\\', '/', static::class));
            throw new \Exception("{$class} not found: {$id}");
        }
        return $model;
    }
    
    /**
     * 从数据库行数组构造模型实例
     */
    public static function fromArray(array $data): static
    {
        $model = new static();
        $model->attributes = $data;
        $model->original = $data;
        $model->exists = true;
        return $model;
    }
    
    /**
     * 从数据库行数组批量构造模型实例
     */
    public static function fromArrayList(array $rows): array
    {
        return array_map(fn($row) => static::fromArray($row), $rows);
    }
    
    /**
     * 检查属性是否存在
     */
    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }
    
    /**
     * 获取主键值
     */
    public function getId(): mixed
    {
        return $this->getAttribute($this->primaryKey);
    }
    
    /**
     * 从数据库重新加载模型数据
     * 
     * 用法: $user->refresh()  // 重新从DB读取最新数据
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }
        
        $id = $this->getAttribute($this->primaryKey);
        $data = static::query()
            ->where($this->primaryKey, '=', $id)
            ->first();
        
        if ($data) {
            $this->attributes = $data;
            $this->original = $data;
        }
        
        return $this;
    }
    
    /**
     * 检查是否有未保存的修改
     * 
     * 用法: $user->name = 'new'; $user->isDirty()       → true
     *       $user->isDirty('name')                        → true
     *       $user->isDirty('email')                       → false
     */
    public function isDirty(?string $key = null): bool
    {
        $dirty = $this->getDirty();
        
        if ($key !== null) {
            return array_key_exists($key, $dirty);
        }
        
        return !empty($dirty);
    }
    
    /**
     * 获取所有已修改但未保存的字段
     * 
     * 用法: $user->name = 'new'; $user->getDirty() → ['name' => 'new']
     */
    public function getDirty(): array
    {
        $dirty = [];
        
        foreach ($this->attributes as $key => $value) {
            if ($key === $this->primaryKey) continue;
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        
        return $dirty;
    }
    
    /**
     * 检查模型是否未被修改
     */
    public function isClean(?string $key = null): bool
    {
        return !$this->isDirty($key);
    }
    
    /**
     * 获取属性的原始值（修改前）
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->original[$key] ?? null;
        }
        return $this->original;
    }
}
