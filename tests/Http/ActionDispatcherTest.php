<?php

declare(strict_types=1);

namespace OmniPHP\Tests\Http;

use OmniPHP\Http\Context;
use OmniPHP\Http\Middlewares\ActionDispatcherMiddleware;
use PHPUnit\Framework\TestCase;

final class FakeUserHandler
{
    public static function profile(Context $ctx): array
    {
        return ['status' => 1, 'uid' => 12345, 'name' => 'Hero'];
    }
}

final class ActionDispatcherTest extends TestCase
{
    public function testDispatchSuccess(): void
    {
        $middleware = new ActionDispatcherMiddleware([
            'user' => FakeUserHandler::class,
        ]);

        $ctx = Context::mock(['c' => 'user', 'm' => 'profile']);
        $result = $middleware->handle($ctx, fn($c) => ['fallback' => true]);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['status']);
        $this->assertSame(12345, $result['uid']);
    }

    public function testFallbackWhenNoParameters(): void
    {
        $middleware = new ActionDispatcherMiddleware([
            'user' => FakeUserHandler::class,
        ]);

        $ctx = Context::mock(['foo' => 'bar']);
        $result = $middleware->handle($ctx, fn($c) => ['fallback' => true]);

        $this->assertSame(['fallback' => true], $result);
    }

    public function testAutoFallbackModeWithLoggerWarning(): void
    {
        $middleware = new ActionDispatcherMiddleware(
            handlers: ['user' => FakeUserHandler::class],
            autoFallback: true
        );

        // 访问未覆盖的接口 c=blackmarket&m=index
        $ctx = Context::mock(['c' => 'blackmarket', 'm' => 'index', 'uid' => 9999]);
        $result = $middleware->handle($ctx, fn($c) => ['fallback' => false]);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['status']);
        $this->assertSame(1, $result['is_fallback']);
        $this->assertSame('blackmarket', $result['controller']);
        $this->assertSame('index', $result['method']);
    }
}
