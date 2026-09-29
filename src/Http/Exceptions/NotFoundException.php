<?php

namespace OmniPHP\Http\Exceptions;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', mixed $details = null)
    {
        parent::__construct($message, 404, [], $details);
    }
}
