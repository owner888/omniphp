<?php
/**
 * Migration 基类
 * 
 * 用于数据库表结构变更管理
 */

namespace OmniPHP\Database;

abstract class Migration
{
    /**
     * 执行迁移
     */
    abstract public function up(): void;
    
    /**
     * 回滚迁移
     */
    abstract public function down(): void;
}
