<?php

declare(strict_types=1);

namespace OmniPHP\Tests\WebSocket;

use OmniPHP\WebSocket\Broadcaster;
use OmniPHP\WebSocket\Context;
use OmniPHP\WebSocket\Gateway;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

final class WebSocketTest extends TestCase
{
    protected function setUp(): void
    {
        Broadcaster::reset();
    }

    public function testContextMockAndAttributes(): void
    {
        $ctx = Context::mock([
            'action' => 'chat',
            'room_id' => 101,
            'message' => 'Hello OmniPHP WebSocket!',
        ], uid: 8888);

        $this->assertSame('chat', $ctx->action());
        $this->assertSame(101, $ctx->input('room_id'));
        $this->assertSame('Hello OmniPHP WebSocket!', $ctx->input('message'));
        $this->assertSame(8888, $ctx->uid());
        $this->assertSame('chat', $ctx->all()['action']);

        $ctx->set('session_id', 'sess_abc');
        $this->assertTrue($ctx->has('session_id'));
        $this->assertSame('sess_abc', $ctx->get('session_id'));

        $this->assertTrue($ctx->emit('chat_ack', ['status' => 'delivered']));
        $this->assertCount(1, $ctx->sentMessages);
        $this->assertStringContainsString('chat_ack', $ctx->sentMessages[0]);

        $this->assertTrue($ctx->error('权限不足', 403));
        $this->assertCount(2, $ctx->sentMessages);
        $this->assertStringContainsString('权限不足', $ctx->sentMessages[1]);
    }

    public function testBroadcasterAndSendToUid(): void
    {
        $mockConn1 = $this->createMock(TcpConnection::class);
        $mockConn1->id = 1;
        $mockConn1->expects($this->exactly(2))->method('send');

        $mockConn2 = $this->createMock(TcpConnection::class);
        $mockConn2->id = 2;
        $mockConn2->expects($this->once())->method('send');

        Broadcaster::register($mockConn1, 1001);
        Broadcaster::register($mockConn2, 1002);

        $this->assertSame(2, Broadcaster::count());

        // 全服广播: 两个连接均收到
        $sent = Broadcaster::broadcast('system_alert', ['text' => '全服维护通知']);
        $this->assertSame(2, $sent);

        // 单播 UID 1001: 仅 mockConn1 收到
        $ok = Broadcaster::sendToUid(1001, 'personal_msg', ['text' => '私聊消息']);
        $this->assertTrue($ok);

        // 注销连接 2
        Broadcaster::unregister($mockConn2);
        $this->assertSame(1, Broadcaster::count());
    }

    public function testBroadcasterSweepZombieConnections(): void
    {
        $zombie = $this->createMock(TcpConnection::class);
        $zombie->id = 999;
        $zombie->expects($this->once())->method('close');

        Broadcaster::register($zombie, 9999);
        $zombie->lastActiveTime = time() - 120; // 模拟 2 分钟无心跳

        $swept = Broadcaster::sweep(60);
        $this->assertSame(1, $swept);
        $this->assertSame(0, Broadcaster::count());
    }

    public function testGatewayProtocolPing(): void
    {
        $mock = $this->createMock(TcpConnection::class);
        $mock->id = 100;
        $mock->expects($this->once())->method('send')->with('ping_data_123');

        Gateway::handleProtocolPing($mock, 'ping_data_123');
        $this->assertGreaterThan(0, $mock->lastActiveTime);
    }
}
