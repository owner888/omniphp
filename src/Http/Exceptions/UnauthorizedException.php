<?php

namespace OmniPHP\Http\Exceptions;

class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', mixed $details = null)
    {
        parent::__construct($message, 401, [], $details);
    }
}
