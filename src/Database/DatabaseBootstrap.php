<?php
/**
 * 数据库启动器
 * 
 * 在服务启动时自动初始化数据库并执行迁移
 */

namespace OmniPHP\Database;

use OmniPHP\Config;
use OmniPHP\Logger;
use Exception;

class DatabaseBootstrap
{
    /**
     * 初始化并执行迁移
     * 
     * 注意：此方法在 Workerman fork 之前的主进程中调用。
     * 完成初始化后会自动关闭连接，防止 fork 后子进程继承文件描述符导致 SQLite 数据损坏。
     * 
     * @param bool $autoMigrate 是否自动执行迁移
     * @return array 执行结果
     */
    public static function init(bool $autoMigrate = true): array
    {
        $result = [
            'success' => false,
            'driver' => null,
            'migrated' => 0,
            'error' => null,
        ];
        
        try {
            // 初始化数据库连接
            DatabaseManager::init();
            $driver = Config::get('database.default', 'sqlite');
            $result['driver'] = $driver;
            
            // SQLite: 启动时执行 WAL checkpoint，确保 WAL 中的数据写回主数据库文件
            // 防止因 WAL 文件损坏导致数据丢失
            if ($driver === 'sqlite') {
                try {
                    DB::connection()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                    Logger::debug("[DB] WAL checkpoint completed on startup");
                } catch (Exception $e) {
                    Logger::warning("[DB] WAL checkpoint failed: " . $e->getMessage());
                }
            }
            
            // 自动执行迁移
            $migrated = 0;
            if ($autoMigrate) {
                $migrationRunner = new MigrationRunner();
                $status = $migrationRunner->status();
                
                if ($status['pending'] > 0) {
                    $executed = $migrationRunner->migrate();
                    $migrated = count($executed);
                    $result['migrated'] = $migrated;
                }
            }
            
            $result['success'] = true;
            
            // 统一的日志输出
            $message = $migrated > 0 
                ? "数据库初始化完成，执行了 {$migrated} 个迁移"
                : "数据库初始化完成";
            
            Logger::debug($message, [
                'driver' => $driver,
                'migrated' => $migrated,
            ]);
            
            // 关闭主进程的数据库连接（防止 fork 后子进程继承文件描述符）
            // 每个 Worker 会在 onWorkerStart 中通过 initWorker() 创建独立连接
            DB::close();
            Logger::debug("[DB] Pre-fork connection closed");
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            
            Logger::error("数据库初始化失败", [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $result;
    }
    
    /**
     * Worker 进程初始化（在 onWorkerStart 中调用）
     * 
     * Workerman fork 后每个 worker 必须创建独立的数据库连接，
     * 否则多个 worker 共享同一个 MySQL socket 会导致数据损坏。
     * 
     * 连接断线时由 DB::query()/DB::execute() 自动检测并重连，无需心跳保活。
     * 
     * 用法: $httpWorker->onWorkerStart = function($worker) {
     *           DatabaseBootstrap::initWorker();
     *       };
     */
    public static function initWorker(): void
    {
        try {
            // 每个 Worker 创建独立的数据库连接
            DatabaseManager::init();
            
            $driver = Config::get('database.default', 'sqlite');
            Logger::debug("[DB] Worker connection initialized", ['driver' => $driver]);
        } catch (Exception $e) {
            Logger::error("[DB] Worker connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * 检查数据库是否已初始化
     */
    public static function isInitialized(): bool
    {
        try {
            $pdo = DB::connection();
            return $pdo !== null;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取数据库驱动
     */
    public static function getDriver(): ?string
    {
        try {
            $pdo = DB::connection();
            return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (Exception $e) {
            return null;
        }
    }
}
