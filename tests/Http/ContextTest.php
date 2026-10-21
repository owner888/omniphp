<?php

declare(strict_types=1);

namespace OmniPHP\Tests\Http;

use OmniPHP\Http\Context;
use OmniPHP\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class CustomGameContext extends Context
{
    public function customPlayerId(): int
    {
        return 777;
    }
}

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

    public function testRouterCustomContextClassInjection(): void
    {
        Router::clear();
        Router::setContextClass(CustomGameContext::class);

        $invokedContext = null;
        Router::get('/game/profile', function (CustomGameContext $ctx) use (&$invokedContext) {
            $invokedContext = $ctx;
            return ['player' => $ctx->customPlayerId()];
        });

        $mockConn = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $req = new Request("GET /game/profile HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        $res = Router::dispatch($mockConn, $req);

        $this->assertSame(['player' => 777], $res);
        $this->assertInstanceOf(CustomGameContext::class, $invokedContext);
        $this->assertSame(777, $invokedContext->customPlayerId());
        Router::clear();
    }
}
