<?php

namespace LucaLongo\Licensing\Enums;

enum TrialStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Expired => __('Expired'),
            self::Converted => __('Converted to Full License'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Expired => 'red',
            self::Converted => 'blue',
            self::Cancelled => 'gray',
        };
    }
}
