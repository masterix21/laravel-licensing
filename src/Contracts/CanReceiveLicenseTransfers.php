<?php

namespace LucaLongo\Licensing\Contracts;

interface CanReceiveLicenseTransfers
{
    /**
     * Determine if the entity can receive license transfers.
     */
    public function canReceiveLicenseTransfers(): bool;

    /**
     * Get the maximum number of licenses this entity can hold.
     */
    public function getMaxLicenseLimit(): ?int;

    /**
     * Get the current number of active licenses.
     */
    public function getActiveLicenseCount(): int;

    /**
     * Determine if the entity has reached its license limit.
     */
    public function hasReachedLicenseLimit(): bool;
}
