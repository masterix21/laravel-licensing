<?php

namespace LucaLongo\Licensing\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \LucaLongo\Licensing\Models\License findByKey(string $key)
 * @method static \LucaLongo\Licensing\Models\LicenseUsage register(\LucaLongo\Licensing\Models\License $license, string $fingerprint, array $metadata = [])
 * @method static string issueToken(\LucaLongo\Licensing\Models\License $license, \LucaLongo\Licensing\Models\LicenseUsage $usage, array $options = [])
 * @method static array verifyToken(string $token, array $options = [])
 * 
 * @see \LucaLongo\Licensing\Licensing
 */
class Licensing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LucaLongo\Licensing\Licensing::class;
    }
}