<?php

namespace OmniPHP\Engines;

use OmniPHP\LoggerEngineInterface;
use OmniPHP\Requests;

/**
 * Elasticsearch Logger Engine (ELK)
 * 
 * 通过 HTTP 将日志发送到 Elasticsearch
 * 支持 Logstash 兼容格式
 */
class ElasticsearchEngine implements LoggerEngineInterface
{
    private string $host;
    private int $port;
    private string $index;
    private string $sessionId;
    private bool $enabled = false;
    private array $extraFields = [];
    private Requests $http;

    /**
     * 构造函数
     * 
     * @param string $host Elasticsearch 主机地址
     * @param int $port Elasticsearch 端口
     * @param string $index 索引名称
     * @param string $sessionId 会话ID，用于分组日志
     */
    public function __construct(
        string $host = 'localhost',
        int $port = 9200,
        string $index = 'logs',
        string $sessionId = ''
    ) {
        $this->host = $host ?: getenv('ELASTICSEARCH_HOST') ?: 'localhost';
        $this->port = $port ?: (int)(getenv('ELASTICSEARCH_PORT') ?: 9200);
        $this->index = $index ?: getenv('ELASTICSEARCH_INDEX') ?: 'logs';
        $this->sessionId = $sessionId ?: getenv('ELASTICSEARCH_SESSION_ID') ?: uniqid('session_', true);
        $this->http = new Requests(['timeout' => 10, 'connect_timeout' => 5]);
        
        $this->enabled = !empty($this->host);
    }

    /**
     * 发送日志到 Elasticsearch
     */
    public function send(string $level, string $message, array $context = []): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $document = $this->buildDocument($level, $message, $context);
        
        $response = $this->http->post("{$this->getBaseUrl()}/{$this->index}/_doc", [
            'json' => $document
        ]);

        $data = $response->json();
        return $response->ok() && isset($data['result']) && in_array($data['result'], ['created', 'updated']);
    }

    /**
     * 批量发送日志
     * 
     * @param array $logs 日志数组，每项 ['level' => ..., 'message' => ..., 'context' => ...]
     */
    public function sendBatch(array $logs): bool
    {
        if (!$this->isAvailable() || empty($logs)) {
            return false;
        }

        $body = '';
        
        foreach ($logs as $log) {
            $doc = $this->buildDocument(
                $log['level'] ?? 'INFO',
                $log['message'] ?? '',
                $log['context'] ?? []
            );
            
            $meta = json_encode([
                'index' => [
                    '_index' => $this->index,
                ]
            ]);
            
            $body .= $meta . "\n" . json_encode($doc, JSON_UNESCAPED_UNICODE) . "\n";
        }

        $response = $this->http->post("{$this->getBaseUrl()}/_bulk", [
            'headers' => ['Content-Type' => 'application/x-ndjson'],
            'data' => $body,
        ]);

        $data = $response->json();
        return isset($data['errors']) && $data['errors'] === false;
    }

    /**
     * 获取引擎名称
     */
    public function getName(): string
    {
        return 'Elasticsearch';
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
     * 设置额外字段
     */
    public function setExtraField(string $key, $value): void
    {
        $this->extraFields[$key] = $value;
    }

    /**
     * 设置额外字段（批量）
     */
    public function setExtraFields(array $fields): void
    {
        $this->extraFields = array_merge($this->extraFields, $fields);
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'index' => $this->index,
            'sessionId' => $this->sessionId,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * 获取基础 URL
     */
    private function getBaseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * 构建 Elasticsearch 文档
     */
    private function buildDocument(string $level, string $message, array $context): array
    {
        return array_merge([
            '@timestamp' => date('c'),
            'level' => strtoupper($level),
            'message' => $message,
            'session_id' => $this->sessionId,
            'context' => $context,
        ], $this->extraFields);
    }
}
