<?php

declare(strict_types=1);

namespace OmniPHP\Tests\Http;

use OmniPHP\Http\Router;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final class StaticServerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        Router::clear();
        $this->tempDir = sys_get_temp_dir() . '/omniphp_static_test_' . uniqid();
        mkdir($this->tempDir . '/assets', 0777, true);
        file_put_contents($this->tempDir . '/assets/app.js', 'console.log("omniphp");');
        file_put_contents($this->tempDir . '/index.html', '<h1>Hello OmniPHP SPA</h1>');
    }

    protected function tearDown(): void
    {
        @unlink($this->tempDir . '/assets/app.js');
        @unlink($this->tempDir . '/index.html');
        @rmdir($this->tempDir . '/assets');
        @rmdir($this->tempDir);
        Router::clear();
    }

    public function testStaticDirectoryServing(): void
    {
        Router::static('/assets', $this->tempDir . '/assets');

        $sentResponse = null;
        $mockConn = $this->createMock(TcpConnection::class);
        $mockConn->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (WorkermanResponse $res) use (&$sentResponse) {
                $sentResponse = $res;
                return true;
            });

        $req = new Request("GET /assets/app.js HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        Router::dispatch($mockConn, $req);

        $this->assertNotNull($sentResponse);
        $this->assertSame(200, $sentResponse->getStatusCode());
        $this->assertStringContainsString('application/javascript', $sentResponse->getHeader('Content-Type'));
        $this->assertSame('console.log("omniphp");', (string)$sentResponse->rawBody());
    }

    public function testStaticFileServing(): void
    {
        Router::staticFile('/', $this->tempDir . '/index.html');

        $sentResponse = null;
        $mockConn = $this->createMock(TcpConnection::class);
        $mockConn->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (WorkermanResponse $res) use (&$sentResponse) {
                $sentResponse = $res;
                return true;
            });

        $req = new Request("GET / HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        Router::dispatch($mockConn, $req);

        $this->assertNotNull($sentResponse);
        $this->assertSame(200, $sentResponse->getStatusCode());
        $this->assertStringContainsString('<h1>Hello OmniPHP SPA</h1>', (string)$sentResponse->rawBody());
    }
}
