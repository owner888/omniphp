<?php
declare(strict_types=1);

namespace OmniPHP\Database;

/**
 * 跨数据库驱动（MySQL / SQLite / PostgreSQL）的 Upsert 冲突更新策略描述子
 *
 * 自动屏蔽 MySQL `VALUES(col)` + `IF(...)` 与 SQLite/PG `excluded.col` + `CASE WHEN ...` 的方言差异。
 */
final class UpsertValue
{
    public const OP_OVERWRITE = 'overwrite';
    public const OP_INCREMENT = 'increment';
    public const OP_DECREMENT = 'decrement';
    public const OP_MIN       = 'min';
    public const OP_MAX       = 'max';

    private function __construct(
        public readonly string $op,
        public readonly int|float $amount = 1,
    ) {}

    public static function overwrite(): self
    {
        return new self(self::OP_OVERWRITE);
    }

    public static function increment(int|float $amount = 1): self
    {
        return new self(self::OP_INCREMENT, $amount);
    }

    public static function decrement(int|float $amount = 1): self
    {
        return new self(self::OP_DECREMENT, $amount);
    }

    public static function min(): self
    {
        return new self(self::OP_MIN);
    }

    public static function max(): self
    {
        return new self(self::OP_MAX);
    }

    /**
     * 编译为当前驱动下的 SET 右侧表达式及绑定参数
     *
     * @return array{0: string, 1: array} [sqlFragment, bindings]
     */
    public function compile(string $driver, string $escapedTable, string $escapedCol): array
    {
        $incoming = ($driver === 'mysql')
            ? "VALUES({$escapedCol})"
            : "excluded.{$escapedCol}";

        // SQLite / PostgreSQL 在 ON CONFLICT DO UPDATE 中引用原表列需加表名前缀以消除与 excluded 的歧义；
        // MySQL ON DUPLICATE KEY UPDATE 右侧直接写列名（或表名.列名均可）。
        $qualifiedCol = ($driver === 'mysql') ? $escapedCol : "{$escapedTable}.{$escapedCol}";

        return match ($this->op) {
            self::OP_OVERWRITE => [$incoming, []],
            self::OP_INCREMENT => ["{$qualifiedCol} + ?", [$this->amount]],
            self::OP_DECREMENT => ["{$qualifiedCol} - ?", [$this->amount]],
            self::OP_MIN => [
                $driver === 'mysql'
                    ? "IF({$escapedCol} IS NULL OR {$incoming} < {$escapedCol}, {$incoming}, {$escapedCol})"
                    : "CASE WHEN {$qualifiedCol} IS NULL OR {$incoming} < {$qualifiedCol} THEN {$incoming} ELSE {$qualifiedCol} END",
                [],
            ],
            self::OP_MAX => [
                $driver === 'mysql'
                    ? "IF({$escapedCol} IS NULL OR {$incoming} > {$escapedCol}, {$incoming}, {$escapedCol})"
                    : "CASE WHEN {$qualifiedCol} IS NULL OR {$incoming} > {$qualifiedCol} THEN {$incoming} ELSE {$qualifiedCol} END",
                [],
            ],
            default => [$incoming, []],
        };
    }
}