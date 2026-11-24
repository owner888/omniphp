<?php
declare(strict_types=1);

namespace OmniPHP\Tests\Database;

use OmniPHP\Database\Blueprint;
use OmniPHP\Database\DB;
use OmniPHP\Database\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaAndUpsertTest extends TestCase
{
    protected function setUp(): void
    {
        DB::init(new PDO('sqlite::memory:'), ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        DB::close();
    }

    public function testColumnUniqueCreatesUniqueIndexInBothCreateAndAlterModes(): void
    {
        Schema::create('crash_groups', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 32)->unique();
            $table->bigInteger('tg_id')->nullable()->unique()->index();
            $table->unsignedInteger('total_count')->default(0);
        });

        // 再次调用 Schema::create 不应因索引已存在报错（幂等）
        Schema::create('crash_groups', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 32)->unique();
            $table->bigInteger('tg_id')->nullable()->unique()->index();
            $table->unsignedInteger('total_count')->default(0);
        });

        $this->assertTrue(Schema::hasIndex('crash_groups', 'uniq_fingerprint'));
        $this->assertTrue(Schema::hasIndex('crash_groups', 'uniq_tg_id'));
        $this->assertFalse(Schema::hasIndex('crash_groups', 'idx_tg_id'), 'Redundant non-unique index should be skipped when unique() is already set');

        // Schema::table alter 模式下新增带 ->unique() 的列同样自动建唯一索引
        Schema::table('crash_groups', function (Blueprint $table) {
            $table->string('external_code', 64)->nullable()->unique('uniq_external_code');
        });
        $this->assertTrue(Schema::hasIndex('crash_groups', 'uniq_external_code'));

        // 验证数据库层唯一约束真实生效
        DB::table('crash_groups')->insert(['fingerprint' => str_repeat('a', 32), 'total_count' => 1]);
        $this->expectException(\PDOException::class);
        DB::table('crash_groups')->insert(['fingerprint' => str_repeat('a', 32), 'total_count' => 1]);
    }

    public function testSchemaDeduplicateMergesAggregatesPreservesNullsAndKeepsTargetRow(): void
    {
        Schema::create('legacy_groups', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 32)->nullable();
            $table->string('reason', 128)->default('');
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->unsignedInteger('total_count')->default(0);
        });

        // 插入 3 条重复 fp1 + 2 条 NULL 指纹（SQL UNIQUE 允许多个 NULL，不应被当成重复误删）
        DB::table('legacy_groups')->insert([
            'fingerprint' => 'fp1', 'reason' => 'old',
            'first_seen_at' => '2026-11-20 10:00:00', 'last_seen_at' => '2026-11-20 10:00:00', 'total_count' => 1,
        ]);
        DB::table('legacy_groups')->insert([
            'fingerprint' => 'fp1', 'reason' => 'earlier backfill',
            'first_seen_at' => '2026-11-18 08:00:00', 'last_seen_at' => '2026-11-25 04:00:00', 'total_count' => 3,
        ]);
        DB::table('legacy_groups')->insert([
            'fingerprint' => 'fp1', 'reason' => 'latest',
            'first_seen_at' => '2026-11-25 02:00:00', 'last_seen_at' => '2026-11-25 02:00:00', 'total_count' => 2,
        ]);
        DB::table('legacy_groups')->insert(['fingerprint' => null, 'reason' => 'null-1', 'total_count' => 1]);
        DB::table('legacy_groups')->insert(['fingerprint' => null, 'reason' => 'null-2', 'total_count' => 1]);

        $removed = Schema::deduplicate('legacy_groups', 'fingerprint', [
            'total_count'   => 'SUM',
            'first_seen_at' => 'MIN',
            'last_seen_at'  => 'MAX',
        ], keep: 'max');

        $this->assertSame(2, $removed);
        $this->assertSame(3, DB::table('legacy_groups')->count());

        $merged = DB::table('legacy_groups')->where('fingerprint', 'fp1')->first();
        $this->assertSame(6, (int)$merged['total_count']);
        $this->assertSame('2026-11-18 08:00:00', $merged['first_seen_at']);
        $this->assertSame('2026-11-25 04:00:00', $merged['last_seen_at']);
        $this->assertSame('latest', $merged['reason']);

        // 幂等性：再次执行返回 0，且唯一索引可直接加上
        $this->assertSame(0, Schema::deduplicate('legacy_groups', 'fingerprint'));
        Schema::table('legacy_groups', fn(Blueprint $t) => $t->unique('fingerprint', 'uniq_legacy_fp'));
        $this->assertTrue(Schema::hasIndex('legacy_groups', 'uniq_legacy_fp'));
    }

    public function testUpsertSupportsIncrementMinMaxAndRawUpdate(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 32)->unique();
            $table->string('reason', 128)->default('');
            $table->string('first_seen_at', 32)->nullable();
            $table->string('last_seen_at', 32)->nullable();
            $table->unsignedInteger('total_count')->default(0);
        });

        // 首次插入
        DB::table('metrics')->upsert([
            'fingerprint'   => 'fp_crash_1',
            'reason'        => 'v1',
            'first_seen_at' => '2026-11-24 12:00:00',
            'last_seen_at'  => '2026-11-24 12:00:00',
            'total_count'   => 1,
        ], 'fingerprint', [
            'reason',
            'first_seen_at' => DB::upsertMin(),
            'last_seen_at'  => DB::upsertMax(),
            'total_count'   => DB::upsertIncrement(),
        ]);

        // 补传更早时间的崩溃：first_seen_at 往前推，last_seen_at 不退步，total_count +1，reason 更新
        DB::table('metrics')->upsert([
            'fingerprint'   => 'fp_crash_1',
            'reason'        => 'v2',
            'first_seen_at' => '2026-11-23 09:00:00',
            'last_seen_at'  => '2026-11-23 09:00:00',
            'total_count'   => 1,
        ], 'fingerprint', [
            'reason',
            'first_seen_at' => DB::upsertMin(),
            'last_seen_at'  => DB::upsertMax(),
            'total_count'   => DB::upsertIncrement(),
        ]);

        // 新崩溃：last_seen_at 往后推，first_seen_at 保持最早，total_count +1
        DB::table('metrics')->upsert([
            'fingerprint'   => 'fp_crash_1',
            'reason'        => 'v3',
            'first_seen_at' => '2026-11-25 05:00:00',
            'last_seen_at'  => '2026-11-25 05:00:00',
            'total_count'   => 1,
        ], 'fingerprint', [
            'reason',
            'first_seen_at' => DB::upsertMin(),
            'last_seen_at'  => DB::upsertMax(),
            'total_count'   => DB::upsertIncrement(),
        ]);

        $row = DB::table('metrics')->where('fingerprint', 'fp_crash_1')->first();
        $this->assertSame(1, DB::table('metrics')->count());
        $this->assertSame(3, (int)$row['total_count']);
        $this->assertSame('2026-11-23 09:00:00', $row['first_seen_at']);
        $this->assertSame('2026-11-25 05:00:00', $row['last_seen_at']);
        $this->assertSame('v3', $row['reason']);

        // DB::raw 在普通 update() 中同样生效
        DB::table('metrics')
            ->where('fingerprint', 'fp_crash_1')
            ->update(['total_count' => DB::raw('"total_count" + ?', [10])]);
        $this->assertSame(13, (int)DB::table('metrics')->where('fingerprint', 'fp_crash_1')->value('total_count'));
    }
}