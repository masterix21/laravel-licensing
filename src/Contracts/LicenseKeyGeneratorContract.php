<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Models\License;

interface LicenseKeyGeneratorContract
{
    /**
     * Generate a new license key.
     *
     * @param License|null $license Optional license instance for context
     * @return string The generated license key
     */
    public function generate(?License $license = null): string;
}