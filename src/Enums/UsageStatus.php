<?php

namespace LucaLongo\Licensing\Enums;

enum UsageStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    
    public function isActive(): bool
    {
        return $this === self::Active;
    }
}