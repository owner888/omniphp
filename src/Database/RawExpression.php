<?php
declare(strict_types=1);

namespace OmniPHP\Database;

/**
 * 原始 SQL 表达式（供 QueryBuilder::update / upsert 等场景嵌入原子运算，免写整条裸 SQL）
 */
final class RawExpression
{
    public function __construct(
        public readonly string $expression,
        public readonly array $bindings = [],
    ) {}
}