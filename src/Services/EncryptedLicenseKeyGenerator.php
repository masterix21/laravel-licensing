<?php

namespace LucaLongo\Licensing\Services;

use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Models\License;

class EncryptedLicenseKeyGenerator implements LicenseKeyGeneratorContract
{
    /**
     * Generate a new license key.
     *
     * @param  License|null  $license  Optional license instance for context
     * @return string The generated license key
     */
    public function generate(?License $license = null): string
    {
        $prefix = config('licensing.key_management.key_prefix', 'LIC');
        $separator = config('licensing.key_management.key_separator', '-');

        // Generate a random key with format: PREFIX-XXXXXX-XXXXXX-XXXXXX-XXXXXX (128-bit entropy)
        $segments = [];
        for ($i = 0; $i < 4; $i++) {
            $segments[] = strtoupper(bin2hex(random_bytes(4)));
        }

        return $prefix.$separator.implode($separator, $segments);
    }
}
