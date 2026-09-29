<?php
/**
 * Migration 运行器
 * 
 * 自动执行数据库迁移
 */

namespace OmniPHP\Database;

use OmniPHP\Logger;

class MigrationRunner
{
    private string $migrationsPath;
    private string $migrationsTable = 'migrations';
    
    public function __construct(?string $migrationsPath = null)
    {
        $this->migrationsPath = $migrationsPath ?? BASE_PATH . '/database/migrations';
    }
    
    /**
     * 创建迁移记录表
     */
    private function ensureMigrationsTable(): void
    {
        if (Schema::hasTable($this->migrationsTable)) {
            return;
        }
        
        Schema::create($this->migrationsTable, function(Blueprint $table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
            $table->dateTime('executed_at');
        });
        
        Logger::debug("创建迁移记录表: {$this->migrationsTable}");
    }
    
    /**
     * 获取已执行的迁移
     */
    private function getExecutedMigrations(): array
    {
        $this->ensureMigrationsTable();
        
        $records = DB::table($this->migrationsTable)->orderBy('id')->get();
        
        return array_column($records, 'migration');
    }
    
    /**
     * 获取待执行的迁移文件
     */
    private function getPendingMigrations(): array
    {
        $executed = $this->getExecutedMigrations();
        
        // 扫描迁移目录
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
            return [];
        }
        
        $files = glob($this->migrationsPath . '/*.php');
        $pending = [];
        
        foreach ($files as $file) {
            $migration = basename($file, '.php');
            
            if (!in_array($migration, $executed)) {
                $pending[] = [
                    'name' => $migration,
                    'file' => $file,
                ];
            }
        }
        
        // 按文件名排序（时间戳顺序）
        usort($pending, fn($a, $b) => strcmp($a['name'], $b['name']));
        
        return $pending;
    }
    
    /**
     * 执行迁移
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        
        $pending = $this->getPendingMigrations();
        
        if (empty($pending)) {
            Logger::debug("没有待执行的迁移");
            return [];
        }
        
        // 获取当前批次号
        $lastBatch = DB::table($this->migrationsTable)
            ->orderBy('batch', 'DESC')
            ->value('batch');
        $batch = ($lastBatch ?? 0) + 1;
        
        $executed = [];
        
        foreach ($pending as $migration) {
            try {
                Logger::debug("执行迁移: {$migration['name']}");
                
                // 加载迁移文件
                $instance = require $migration['file'];
                
                if (!$instance instanceof Migration) {
                    throw new \Exception("迁移文件必须返回 Migration 实例");
                }
                
                // DDL（CREATE TABLE、ALTER TABLE）不使用事务：
                // - SQLite: DDL 在事务中可能导致数据丢失
                // - MySQL: DDL 会触发隐式 COMMIT，事务包裹是假保护
                // 两者都直接执行 DDL，失败则抛异常由外层 catch 处理
                $instance->up();
                
                DB::table($this->migrationsTable)->insert([
                    'migration' => $migration['name'],
                    'batch' => $batch,
                    'executed_at' => date('Y-m-d H:i:s'),
                ]);
                
                $executed[] = $migration['name'];
                Logger::info("✓ 迁移成功: {$migration['name']}");
                
            } catch (\Exception $e) {
                Logger::error("✗ 迁移失败: {$migration['name']}", [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
        
        return $executed;
    }
    
    /**
     * 回滚迁移（最后一个批次）
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationsTable();
        
        // 获取最后 N 个批次
        $batches = DB::table($this->migrationsTable)
            ->select('batch')
            ->distinct()
            ->orderBy('batch', 'DESC')
            ->limit($steps)
            ->get();
        
        if (empty($batches)) {
            Logger::debug("没有可回滚的迁移");
            return [];
        }
        
        $batchNumbers = array_column($batches, 'batch');
        
        // 获取这些批次的所有迁移
        $migrations = DB::table($this->migrationsTable)
            ->whereIn('batch', $batchNumbers)
            ->orderBy('id', 'DESC')
            ->get();
        
        $rolledBack = [];
        
        foreach ($migrations as $record) {
            $file = $this->migrationsPath . '/' . $record['migration'] . '.php';
            
            if (!file_exists($file)) {
                Logger::warning("迁移文件不存在: {$record['migration']}");
                continue;
            }
            
            try {
                Logger::debug("回滚迁移: {$record['migration']}");
                
                // 加载迁移文件
                $instance = require $file;
                
                // 开始事务
                DB::beginTransaction();
                
                try {
                    // 执行 down()
                    $instance->down();
                    
                    // 删除迁移记录
                    DB::table($this->migrationsTable)
                        ->where('id', $record['id'])
                        ->delete();
                    
                    // 提交事务
                    DB::commit();
                    
                    $rolledBack[] = $record['migration'];
                    Logger::info("✓ 回滚成功: {$record['migration']}");
                    
                } catch (\Exception $e) {
                    // 回滚事务
                    DB::rollBack();
                    throw $e;
                }
                
            } catch (\Exception $e) {
                Logger::error("✗ 回滚失败: {$record['migration']}", [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
        
        return $rolledBack;
    }
    
    /**
     * 重置所有迁移
     */
    public function reset(): array
    {
        $this->ensureMigrationsTable();
        
        // 获取所有迁移记录
        $migrations = DB::table($this->migrationsTable)
            ->orderBy('id', 'DESC')
            ->get();
        
        $reset = [];
        
        foreach ($migrations as $record) {
            $file = $this->migrationsPath . '/' . $record['migration'] . '.php';
            
            if (file_exists($file)) {
                $instance = require $file;
                
                try {
                    $instance->down();
                    $reset[] = $record['migration'];
                } catch (\Exception $e) {
                    Logger::error("重置失败: {$record['migration']}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        // 清空迁移记录
        DB::table($this->migrationsTable)->truncate();
        
        return $reset;
    }
    
    /**
     * 刷新数据库（reset + migrate）
     */
    public function refresh(): array
    {
        $this->reset();
        return $this->migrate();
    }
    
    /**
     * 获取迁移状态
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        
        $executed = $this->getExecutedMigrations();
        $pending = $this->getPendingMigrations();
        
        return [
            'executed' => count($executed),
            'pending' => count($pending),
            'executed_list' => $executed,
            'pending_list' => array_column($pending, 'name'),
        ];
    }
}
