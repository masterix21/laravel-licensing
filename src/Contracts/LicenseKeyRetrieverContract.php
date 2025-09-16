<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Models\License;

interface LicenseKeyRetrieverContract
{
    /**
     * Retrieve the license key for a given license.
     *
     * @param License $license
     * @return string|null The license key or null if not retrievable
     */
    public function retrieve(License $license): ?string;

    /**
     * Check if the service supports key retrieval.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}