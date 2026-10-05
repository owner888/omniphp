<?php

namespace OmniPHP\Tests\Database;

use OmniPHP\Database\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * 只测 SQL 拼接与 bindings，不落库：用内存 SQLite 提供 driver 名（标识符转义用双引号）
 */
final class QueryBuilderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
    }

    private function table(string $name = 'users'): QueryBuilder
    {
        return new QueryBuilder($this->pdo, $name);
    }

    public function testPlainSelect(): void
    {
        $this->assertSame('SELECT * FROM "users"', $this->table()->toSql());
    }

    public function testWhereChainProducesPlaceholdersAndBindings(): void
    {
        $q = $this->table()->where('status', 1)->where('age', '>', 18)->orWhere('vip', true);

        $this->assertSame(
            'SELECT * FROM "users" WHERE "status" = ? AND "age" > ? OR "vip" = ?',
            $q->toSql()
        );
        $this->assertSame([1, 18, true], $q->getBindings());
    }

    public function testWhereInNullAndBetween(): void
    {
        $q = $this->table()
            ->whereIn('id', [1, 2, 3])
            ->whereNull('deleted_at')
            ->whereBetween('score', 10, 20);

        $this->assertSame(
            'SELECT * FROM "users" WHERE "id" IN (?, ?, ?) AND "deleted_at" IS NULL AND "score" BETWEEN ? AND ?',
            $q->toSql()
        );
        $this->assertSame([1, 2, 3, 10, 20], $q->getBindings());
    }

    public function testClosureNestsConditions(): void
    {
        $q = $this->table()
            ->where('active', 1)
            ->where(function (QueryBuilder $sub) {
                $sub->where('role', 'admin')->orWhere('role', 'owner');
            });

        $this->assertSame(
            'SELECT * FROM "users" WHERE "active" = ? AND ("role" = ? OR "role" = ?)',
            $q->toSql()
        );
        $this->assertSame([1, 'admin', 'owner'], $q->getBindings());
    }

    public function testSelectRawReplacesSelectInsteadOfAppending(): void
    {
        $q = $this->table()->select('id', 'name')->selectRaw('COUNT(*) AS total');

        $this->assertSame('SELECT COUNT(*) AS total FROM "users"', $q->toSql());
    }

    public function testQualifiedColumnsAndAliases(): void
    {
        $q = $this->table('users as u')
            ->select('u.id', 'u.name as label')
            ->leftJoin('orders', 'orders.user_id', '=', 'u.id');

        $this->assertSame(
            'SELECT "u"."id", "u"."name" AS "label" FROM "users" AS "u" LEFT JOIN "orders" ON "orders"."user_id" = "u"."id"',
            $q->toSql()
        );
    }

    public function testOrderLimitOffset(): void
    {
        $q = $this->table()->orderBy('created_at', 'desc')->limit(10)->offset(20);

        $this->assertSame('SELECT * FROM "users" ORDER BY "created_at" DESC LIMIT 10 OFFSET 20', $q->toSql());
    }

    public function testForceIndexUsesDriverSyntax(): void
    {
        $q = $this->table()->forceIndex('idx_status')->where('status', 1);

        $this->assertSame('SELECT * FROM "users" INDEXED BY "idx_status" WHERE "status" = ?', $q->toSql());
    }

    public function testWhereRawKeepsExpressionAndBindings(): void
    {
        $q = $this->table()->whereRaw('LENGTH(name) > ?', [5])->where('status', 1);

        $this->assertSame('SELECT * FROM "users" WHERE LENGTH(name) > ? AND "status" = ?', $q->toSql());
        $this->assertSame([5, 1], $q->getBindings());
    }

    public function testIdentifiersAreStrippedOfUnsafeCharacters(): void
    {
        $q = $this->table('users; DROP TABLE x')->where('na`me', 'x');

        $this->assertSame('SELECT * FROM "usersDROPTABLEx" WHERE "name" = ?', $q->toSql());
    }

    public function testBindingsAreRebuiltOnEveryToSql(): void
    {
        $q = $this->table()->where('a', 1);
        $q->toSql();
        $q->toSql();

        $this->assertSame([1], $q->getBindings());
    }
}
