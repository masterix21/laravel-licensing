<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

interface UsageRegistrar
{
    public function register(
        License $license,
        string $fingerprint,
        array $metadata = []
    ): LicenseUsage;

    public function heartbeat(LicenseUsage $usage): void;

    public function revoke(LicenseUsage $usage, ?string $reason = null): void;

    public function findByFingerprint(License $license, string $fingerprint): ?LicenseUsage;

    public function canRegister(License $license, string $fingerprint): bool;
}
