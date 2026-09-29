<?php

namespace OmniPHP\Engines;

use OmniPHP\LoggerEngineInterface;
use OmniPHP\Requests;
use OmniPHP\Config;

/**
 * Telegram Logger Engine
 * 
 * 通过 Telegram Bot 发送日志消息
 * 配置从 config/app.php 中的 logging.telegram 读取
 */
class TelegramEngine implements LoggerEngineInterface
{
    private string $botToken;
    private string $chatId;
    private string $apiUrl;
    private bool $enabled = false;
    private Requests $http;
    private array $allowedLevels;

    /**
     * 构造函数
     * 自动从配置文件读取设置
     */
    public function __construct()
    {
        $config = Config::get('app.logging.telegram', []);
        
        $this->botToken = $config['bot_token'] ?? '';
        $this->chatId = $config['chat_id'] ?? '';
        $this->allowedLevels = array_map('strtoupper', $config['allowed_levels'] ?? ['ERROR', 'WARNING']);
        
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->http = new Requests(['timeout' => 10, 'connect_timeout' => 5]);
        
        // 检查是否启用：需要配置了 token 和 chat_id，并且 enabled 为 true
        $configEnabled = $config['enabled'] ?? false;
        $this->enabled = $configEnabled && !empty($this->botToken) && !empty($this->chatId);
    }

    /**
     * 发送日志到 Telegram
     */
    public function send(string $level, string $message, string|array $context = []): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        // 检查日志级别是否被允许发送
        if (!$this->isLevelAllowed($level)) {
            return true; // 返回 true 但不发送，表示没有错误
        }

        $coloredLevel = $this->getLevelEmoji($level) . ' ' . strtoupper($level);
        
        $text = "<b>{$coloredLevel}</b>\n" .
                "<code>" . date('Y-m-d H:i:s') . "</code>\n\n" .
                $this->escapeHtml($message);

        // 如果有上下文，添加额外信息
        if (!empty($context)) {
            // 如果是 string 直接使用，如果是 array 才 json_encode
            $contextText = is_array($context) 
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : $context;
            $text .= "\n\n<pre>{$contextText}</pre>";
        }

        $response = $this->http->post("{$this->apiUrl}/sendMessage", [
            'json' => [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]
        ]);

        if (!$response->ok()) {
            return false;
        }

        $data = $response->json();
        if (!is_array($data)) {
            $decoded = json_decode($response->text(), true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return (bool)($data['ok'] ?? true);
    }

    /**
     * 发送错误日志（带错误级别颜色标记）
     */
    public function sendError(string $message, ?string $file = null, ?int $line = null): bool
    {
        $fullMessage = $message;
        if ($file !== null) {
            $fullMessage .= "\n📁 {$file}" . ($line !== null ? ":{$line}" : '');
        }
        
        $text = "<b>🔴 ERROR</b>\n" .
                "<code>" . date('Y-m-d H:i:s') . "</code>\n\n" .
                $this->escapeHtml($fullMessage);

        $response = $this->http->post("{$this->apiUrl}/sendMessage", [
            'json' => [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]
        ]);

        if (!$response->ok()) {
            return false;
        }

        $data = $response->json();
        if (!is_array($data)) {
            $decoded = json_decode($response->text(), true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return (bool)($data['ok'] ?? true);
    }

    /**
     * 获取引擎名称
     */
    public function getName(): string
    {
        return 'Telegram';
    }

    /**
     * 检查引擎是否可用
     */
    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    /**
     * 启用/禁用引擎
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return [
            'bot_token_set' => !empty($this->botToken),
            'chat_id_set' => !empty($this->chatId),
            'enabled' => $this->enabled,
            'allowed_levels' => $this->allowedLevels,
        ];
    }

    /**
     * 检查日志级别是否被允许
     */
    private function isLevelAllowed(string $level): bool
    {
        return in_array(strtoupper($level), $this->allowedLevels, true);
    }

    /**
     * 获取允许发送的日志级别（只读，用于查看配置）
     */
    public function getAllowedLevels(): array
    {
        return $this->allowedLevels;
    }

    /**
     * 测试连接
     */
    public function test(): array
    {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'Token or Chat ID not configured',
            ];
        }

        $response = $this->http->get("{$this->apiUrl}/getMe");
        
        $data = $response->json();
        if ($response->ok() && ($data['ok'] ?? false)) {
            return [
                'success' => true,
                'bot_name' => $data['result']['username'] ?? 'Unknown',
                'message' => 'Telegram bot connected successfully',
            ];
        }
        
        return [
            'success' => false,
            'message' => $data['description'] ?? 'Unknown error',
        ];
    }

    /**
     * 获取级别对应的 emoji
     */
    private function getLevelEmoji(string $level): string
    {
        return match (strtoupper($level)) {
            'INFO' => '🟢',
            'WARN', 'WARNING' => '🟡',
            'DEBUG' => '🔵',
            'ERROR' => '🔴',
            default => '⚪',
        };
    }

    /**
     * HTML 转义
     */
    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
