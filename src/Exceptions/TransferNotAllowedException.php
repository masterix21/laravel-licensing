<?php

namespace LucaLongo\Licensing\Exceptions;

use Exception;

class TransferNotAllowedException extends Exception
{
    protected string $reason;

    public function __construct(string $message = '', string $reason = '', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->reason = $reason ?: $message;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}