<?php
/**
 * HTTP 异常基类
 * 
 * 所有 HTTP 相关的异常都应继承此类
 */

namespace OmniPHP\Http\Exceptions;

use Exception;

class HttpException extends Exception
{
    protected int $statusCode;
    protected array $headers;
    protected mixed $details;
    
    public function __construct(
        string $message = '',
        int $statusCode = 500,
        array $headers = [],
        mixed $details = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->details = $details;
    }
    
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    public function getDetails(): mixed
    {
        return $this->details;
    }
    
    public function toArray(): array
    {
        return [
            'error' => $this->getMessage(),
            'status_code' => $this->statusCode,
            'details' => $this->details,
        ];
    }
}
