<?php

declare(strict_types=1);

namespace OmniPHP\Tests;

use OmniPHP\ArchitectureGuard;
use PHPUnit\Framework\TestCase;

final class ArchitectureGuardTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/omniphp_guard_test_' . uniqid();
        mkdir($this->tempDir . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/src/Context.php');
        @rmdir($this->tempDir . '/src');
        @rmdir($this->tempDir);
    }

    public function testDetectsCustomContextWrapper(): void
    {
        file_put_contents($this->tempDir . '/src/Context.php', '<?php class Context {}');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('【OmniPHP 架构铁律违规】');

        ArchitectureGuard::verify($this->tempDir);
    }

    public function testAllowsLegitimateSubclassExtension(): void
    {
        // 当用户编写的 Context 正规继承了 OmniPHP\Http\Context 时，架构守卫应当允许并放行！
        file_put_contents($this->tempDir . '/src/Context.php', '<?php namespace App; class Context extends \OmniPHP\Http\Context {}');

        $this->expectNotToPerformAssertions();
        ArchitectureGuard::verify($this->tempDir);
    }

    public function testPassesWhenClean(): void
    {
        $this->expectNotToPerformAssertions();
        ArchitectureGuard::verify($this->tempDir);
    }
}
