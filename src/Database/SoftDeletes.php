<?php
/**
 * 软删除 Trait
 * 
 * 在 Model 中使用 `use SoftDeletes;` 即可启用软删除。
 * 需要表中有 `deleted_at` 字段（通过 Schema 的 $table->softDeletes() 添加）
 * 
 * 用法:
 * class User extends Model {
 *     use SoftDeletes;
 * }
 * 
 * $user->delete();               // 软删除（设置 deleted_at）
 * $user->forceDelete();          // 真正删除
 * $user->restore();              // 恢复已软删除的记录
 * $user->trashed();              // 是否已被软删除
 * 
 * User::query()->get();          // 默认排除已删除（需手动加 whereNull）
 * User::withTrashed()->get();    // 包含已删除
 * User::onlyTrashed()->get();    // 只查已删除
 */

namespace OmniPHP\Database;

trait SoftDeletes
{
    /**
     * 获取软删除列名
     */
    public function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }
    
    /**
     * 软删除（设置 deleted_at 时间戳）
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        
        $column = $this->getDeletedAtColumn();
        $now = date('Y-m-d H:i:s');
        
        $id = $this->getAttribute($this->primaryKey);
        
        $affected = DB::table($this->getTable())
            ->where($this->primaryKey, '=', $id)
            ->update([$column => $now]);
        
        if ($affected >= 0) {
            $this->setAttribute($column, $now);
            return true;
        }
        
        return false;
    }
    
    /**
     * 真正删除（从数据库中移除）
     */
    public function forceDelete(): bool
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
     * 恢复已软删除的记录
     */
    public function restore(): bool
    {
        if (!$this->exists) {
            return false;
        }
        
        $column = $this->getDeletedAtColumn();
        $id = $this->getAttribute($this->primaryKey);
        
        $affected = DB::table($this->getTable())
            ->where($this->primaryKey, '=', $id)
            ->update([$column => null]);
        
        if ($affected >= 0) {
            $this->setAttribute($column, null);
            return true;
        }
        
        return false;
    }
    
    /**
     * 是否已被软删除
     */
    public function trashed(): bool
    {
        $column = $this->getDeletedAtColumn();
        return $this->getAttribute($column) !== null;
    }
    
    /**
     * 查询（包含已软删除的记录，不加 deleted_at IS NULL 过滤）
     * 
     * 用法: User::withTrashed()->where('status', 1)->get()
     */
    public static function withTrashed(): QueryBuilder
    {
        $instance = new static();
        return DB::table($instance->getTable());
    }
    
    /**
     * 只查已软删除的记录
     * 
     * 用法: User::onlyTrashed()->get()
     */
    public static function onlyTrashed(): QueryBuilder
    {
        $instance = new static();
        return DB::table($instance->getTable())
            ->whereNotNull($instance->getDeletedAtColumn());
    }
    
    /**
     * 覆盖默认查询 — 自动排除已软删除的记录
     * 
     * 注意: 使用 SoftDeletes 时，默认查询会自动添加 WHERE deleted_at IS NULL
     * 如果需要包含已删除记录，使用 withTrashed()
     */
    public static function query(): QueryBuilder
    {
        $instance = new static();
        return DB::table($instance->getTable())
            ->whereNull($instance->getDeletedAtColumn());
    }
}
