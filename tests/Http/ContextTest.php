<?php

declare(strict_types=1);

namespace OmniPHP\Tests\Http;

use OmniPHP\Http\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testMockGetRequest(): void
    {
        $ctx = Context::mock(['name' => 'Omni', 'page' => '2'], ['X-App-Version' => '1.0.0']);

        $this->assertSame('Omni', $ctx->query('name'));
        $this->assertSame('2', $ctx->input('page'));
        $this->assertSame('1.0.0', $ctx->header('X-App-Version'));
        $this->assertSame('GET', $ctx->method());

        $ctx->set('user_id', 42);
        $this->assertTrue($ctx->has('user_id'));
        $this->assertSame(42, $ctx->get('user_id'));
    }

    public function testMockPostRequest(): void
    {
        $ctx = Context::mock(['action' => 'login', 'token' => 'abc'], [], 'POST');

        $this->assertSame('login', $ctx->post('action'));
        $this->assertSame('abc', $ctx->input('token'));
        $this->assertSame('POST', $ctx->method());
    }
}
