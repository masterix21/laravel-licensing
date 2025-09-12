<?php

namespace LucaLongo\Licensing\Exceptions;

use Exception;

class TransferValidationException extends Exception
{
    protected array $errors = [];

    public function __construct(string $message = '', array $errors = [], int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }
}
