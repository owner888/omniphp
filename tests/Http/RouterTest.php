<?php

namespace OmniPHP\Tests\Http;

use OmniPHP\Http\Context;
use OmniPHP\Http\ExceptionHandler;
use OmniPHP\Http\Middleware;
use OmniPHP\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    private function dispatch(string $method, string $path): mixed
    {
        $connection = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $request = new Request("{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
        return Router::dispatch($connection, $request);
    }

    private function recorder(string $name, array &$log): Middleware
    {
        return new class($name, $log) implements Middleware {
            public function __construct(private string $name, private array &$log) {}
            public function handle(Context $ctx, callable $next): mixed
            {
                $this->log[] = $this->name . ':in';
                $result = $next($ctx);
                $this->log[] = $this->name . ':out';
                return $result;
            }
        };
    }

    public function testTypedParamIsMatchedAndCast(): void
    {
        Router::get('/books/{id:int}', fn(Context $ctx) => $ctx->param('id'));

        $this->assertSame(42, $this->dispatch('GET', '/books/42'));
    }

    public function testIntParamRejectsNonDigits(): void
    {
        Router::get('/books/{id:int}', fn(Context $ctx) => 'matched');

        $result = $this->dispatch('GET', '/books/abc');

        $this->assertSame('Not Found', $result['error']);
        $this->assertSame('/books/abc', $result['path']);
    }

    public function testGroupPrefixesNest(): void
    {
        Router::group('/api', function () {
            Router::group('/v1', function () {
                Router::get('/ping', fn() => 'pong');
            });
        });

        $this->assertSame('pong', $this->dispatch('GET', '/api/v1/ping'));
        $this->assertSame('/api/v1/ping', Router::getRoutes()[0]['path']);
    }

    public function testMiddlewareOrderIsGlobalThenGroupThenRoute(): void
    {
        $log = [];
        Router::middleware($this->recorder('global', $log));
        Router::group('/g', function () use (&$log) {
            Router::get('/x', fn() => 'ok', [$this->recorder('route', $log)]);
        }, [$this->recorder('group', $log)]);

        $this->assertSame('ok', $this->dispatch('GET', '/g/x'));
        $this->assertSame(
            ['global:in', 'group:in', 'route:in', 'route:out', 'group:out', 'global:out'],
            $log
        );
    }

    public function testHeadReusesGetRoute(): void
    {
        Router::get('/blob', fn() => 'body');

        $this->assertSame('body', $this->dispatch('HEAD', '/blob'));
    }

    public function testMethodMismatchIsNotFound(): void
    {
        Router::post('/only-post', fn() => 'x');

        $this->assertSame('Not Found', $this->dispatch('GET', '/only-post')['error']);
    }

    public function testPathTraversalIsRejectedBeforeMatching(): void
    {
        Router::get('/files/{name}', fn() => 'served');

        $this->assertSame('Invalid path', $this->dispatch('GET', '/files/../etc/passwd')['error']);
    }

    public function testArrayHandlerInstantiatesClass(): void
    {
        Router::get('/hello/{name:alpha}', [RouterTestHandler::class, 'hello']);

        $this->assertSame('hello bob', $this->dispatch('GET', '/hello/bob'));
    }

    public function testAnyParamIsHtmlEscaped(): void
    {
        Router::get('/echo/{text}', fn(Context $ctx) => $ctx->param('text'));

        $this->assertSame('&lt;b&gt;', $this->dispatch('GET', '/echo/<b>'));
    }

    public function testExceptionHandlerTurnsThrowableIntoResponse(): void
    {
        Router::setExceptionHandler(new ExceptionHandler(debug: false));
        Router::get('/boom', function () {
            throw new \RuntimeException('secret detail');
        });

        $result = $this->dispatch('GET', '/boom');

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(500, $result->getStatusCode());
        $this->assertStringNotContainsString('secret detail', (string)$result->rawBody());
    }

    public function testWithoutExceptionHandlerThrowableBubbles(): void
    {
        Router::get('/boom', function () {
            throw new \RuntimeException('bubble');
        });

        $this->expectExceptionMessage('bubble');
        $this->dispatch('GET', '/boom');
    }
}

final class RouterTestHandler
{
    public function hello(Context $ctx): string
    {
        return 'hello ' . $ctx->param('name');
    }
}
