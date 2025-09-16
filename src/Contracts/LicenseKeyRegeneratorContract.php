<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Models\License;

interface LicenseKeyRegeneratorContract
{
    /**
     * Regenerate the license key for a given license.
     *
     * @param License $license
     * @return string The new license key
     */
    public function regenerate(License $license): string;

    /**
     * Check if the service supports key regeneration.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}