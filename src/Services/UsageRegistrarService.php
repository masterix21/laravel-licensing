<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Enums\OverLimitPolicy;
use LucaLongo\Licensing\Events\UsageLimitReached;
use LucaLongo\Licensing\Events\UsageRegistered;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

class UsageRegistrarService implements UsageRegistrar
{
    public function register(
        License $license,
        string $fingerprint,
        array $metadata = []
    ): LicenseUsage {
        return DB::transaction(function () use ($license, $fingerprint, $metadata) {
            $license->lockForUpdate();

            $existingUsage = $this->findByFingerprint($license, $fingerprint);
            
            if ($existingUsage && $existingUsage->isActive()) {
                $existingUsage->heartbeat();
                return $existingUsage;
            }

            if (! $this->canRegister($license, $fingerprint)) {
                $policy = $license->getOverLimitPolicy();
                
                if ($policy === OverLimitPolicy::Reject) {
                    event(new UsageLimitReached($license, $fingerprint, $metadata));
                    throw new \RuntimeException('License usage limit reached');
                }

                if ($policy === OverLimitPolicy::AutoReplaceOldest) {
                    $this->revokeOldestUsage($license);
                }
            }

            $usage = $license->usages()->create([
                'usage_fingerprint' => $fingerprint,
                'status' => 'active',
                'registered_at' => now(),
                'last_seen_at' => now(),
                'client_type' => $metadata['client_type'] ?? null,
                'name' => $metadata['name'] ?? null,
                'ip' => $metadata['ip'] ?? request()->ip(),
                'user_agent' => $metadata['user_agent'] ?? request()->userAgent(),
                'meta' => $metadata['meta'] ?? null,
            ]);

            event(new UsageRegistered($usage));

            return $usage;
        });
    }

    public function heartbeat(LicenseUsage $usage): void
    {
        if (! $usage->isActive()) {
            throw new \RuntimeException('Cannot heartbeat revoked usage');
        }

        $usage->heartbeat();
    }

    public function revoke(LicenseUsage $usage, string $reason = null): void
    {
        $usage->revoke($reason);
    }

    public function findByFingerprint(License $license, string $fingerprint): ?LicenseUsage
    {
        $scope = $license->getUniqueUsageScope();

        if ($scope === 'global') {
            return LicenseUsage::forFingerprint($fingerprint)
                ->active()
                ->first();
        }

        return $license->usages()
            ->forFingerprint($fingerprint)
            ->first();
    }

    public function canRegister(License $license, string $fingerprint): bool
    {
        if (! $license->isUsable()) {
            return false;
        }

        $existingUsage = $this->findByFingerprint($license, $fingerprint);
        
        if ($existingUsage && $existingUsage->isActive()) {
            return true;
        }

        return $license->hasAvailableSeats();
    }

    protected function revokeOldestUsage(License $license): void
    {
        $oldestUsage = $license->activeUsages()
            ->orderBy('last_seen_at')
            ->first();

        if ($oldestUsage) {
            $oldestUsage->revoke('auto_replaced');
        }
    }
}