<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Models\License;

interface CanInitiateLicenseTransfers
{
    /**
     * Determine if the entity can initiate a license transfer.
     */
    public function canInitiateLicenseTransfer(License $license): bool;

    /**
     * Determine if the entity owns the given license.
     */
    public function ownsLicense(License $license): bool;

    /**
     * Get the entity's role in relation to the license.
     */
    public function getLicenseRole(License $license): ?string;
}