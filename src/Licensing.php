<?php

namespace LucaLongo\Licensing;

use LucaLongo\Licensing\Contracts\TokenIssuer;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

class Licensing
{
    public function __construct(
        protected UsageRegistrar $usageRegistrar,
        protected TokenIssuer $tokenIssuer,
        protected TokenVerifier $tokenVerifier
    ) {
    }

    public function findByKey(string $key): ?License
    {
        return License::findByKey($key);
    }

    public function register(License $license, string $fingerprint, array $metadata = []): LicenseUsage
    {
        return $this->usageRegistrar->register($license, $fingerprint, $metadata);
    }

    public function issueToken(License $license, LicenseUsage $usage, array $options = []): string
    {
        if (! $license->isOfflineTokenEnabled()) {
            throw new \RuntimeException('Offline tokens are not enabled for this license');
        }

        return $this->tokenIssuer->issue($license, $usage, $options);
    }

    public function verifyToken(string $token, array $options = []): array
    {
        return $this->tokenVerifier->verify($token, $options);
    }

    public function verifyOfflineToken(string $token, string $publicKeyBundle): array
    {
        return $this->tokenVerifier->verifyOffline($token, $publicKeyBundle);
    }

    public function canRegister(License $license, string $fingerprint): bool
    {
        return $this->usageRegistrar->canRegister($license, $fingerprint);
    }

    public function heartbeat(LicenseUsage $usage): void
    {
        $this->usageRegistrar->heartbeat($usage);
    }

    public function revoke(LicenseUsage $usage, ?string $reason = null): void
    {
        $this->usageRegistrar->revoke($usage, $reason);
    }
}
