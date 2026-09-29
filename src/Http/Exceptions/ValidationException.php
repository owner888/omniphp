<?php

namespace OmniPHP\Http\Exceptions;

class ValidationException extends HttpException
{
    public function __construct(string $message = 'Validation Failed', mixed $details = null)
    {
        parent::__construct($message, 400, [], $details);
    }
}
