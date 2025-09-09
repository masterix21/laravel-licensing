<?php

namespace LucaLongo\Licensing\Enums;

enum LicenseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    
    public function isUsable(): bool
    {
        return in_array($this, [self::Active, self::Grace]);
    }
    
    public function canActivate(): bool
    {
        return $this === self::Pending;
    }
    
    public function canRenew(): bool
    {
        return in_array($this, [self::Active, self::Grace, self::Expired]);
    }
}