<?php

namespace OmniPHP\Tests;

use OmniPHP\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/fixtures/lang';

    protected function setUp(): void
    {
        Lang::reset();
    }

    protected function tearDown(): void
    {
        Lang::reset();
    }

    public function testInitWithoutLangDirThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Lang::init(['default' => 'en']);
    }

    public function testAlwaysLoadFilesAreAvailableAfterInit(): void
    {
        Lang::init(['default' => 'en', 'lang_dir' => self::LANG_DIR, 'always_load' => ['common']]);

        $this->assertSame('Hello', Lang::get('greeting'));
        $this->assertTrue(Lang::has('greeting'));
    }

    public function testMissingKeyFallsBackToDefaultThenKey(): void
    {
        Lang::init(['default' => 'en', 'lang_dir' => self::LANG_DIR, 'always_load' => ['common']]);

        $this->assertSame('n/a', Lang::get('nope', 'n/a'));
        $this->assertSame('nope', Lang::get('nope'));
    }

    public function testCurrentLocaleOverridesFallbackAndFallbackFillsGaps(): void
    {
        Lang::init(['default' => 'zh-cn', 'fallback' => 'en', 'lang_dir' => self::LANG_DIR, 'always_load' => ['common']]);

        $this->assertSame('你好', Lang::get('greeting'));
        $this->assertSame('English only', Lang::get('only_in_en'));
    }

    public function testSwitchLocaleReloadsEveryAlwaysLoadFile(): void
    {
        Lang::init(['default' => 'en', 'lang_dir' => self::LANG_DIR, 'always_load' => ['common', 'extra']]);
        $this->assertSame('Hello', Lang::get('greeting'));

        Lang::switchLocale('zh-cn');

        $this->assertSame('zh-cn', Lang::getLocale());
        $this->assertSame('你好', Lang::get('greeting'));
        $this->assertSame('Extra', Lang::get('extra_key'), 'extra.ini 切换语言后也要重新加载');
    }

    public function testNamedAndPositionalPlaceholders(): void
    {
        Lang::init(['default' => 'en', 'lang_dir' => self::LANG_DIR, 'always_load' => ['common']]);

        $this->assertSame('Welcome, Ada', Lang::get('welcome', null, ['name' => 'Ada']));
        $this->assertSame('3 items', Lang::get('count_fmt', null, [3]));
    }

    public function testKeyLookupIsCaseInsensitive(): void
    {
        Lang::init(['default' => 'en', 'lang_dir' => self::LANG_DIR, 'always_load' => ['common']]);

        $this->assertSame('Hello', Lang::get('GREETING'));
    }
}
