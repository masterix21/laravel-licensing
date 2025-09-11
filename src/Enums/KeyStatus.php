<?php

namespace LucaLongo\Licensing\Enums;

enum KeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
