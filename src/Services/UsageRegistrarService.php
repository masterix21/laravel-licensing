<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Enums\OverLimitPolicy;
use LucaLongo\Licensing\Events\UsageLimitReached;
use LucaLongo\Licensing\Events\UsageRegistered;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingAuditLog;

class UsageRegistrarService implements UsageRegistrar
{
    public function register(
        License $license,
        string $fingerprint,
        array $metadata = []
    ): LicenseUsage {
        $shouldLogLimitReached = false;
        $auditData = [];

        try {
            return DB::transaction(function () use ($license, $fingerprint, $metadata, &$shouldLogLimitReached, &$auditData) {
                $license->lockForUpdate();

                $existingUsage = $this->findByFingerprint($license, $fingerprint);

                if ($existingUsage && $existingUsage->isActive()) {
                    // Check if it's the same license
                    if ($existingUsage->license_id === $license->id) {
                        $existingUsage->heartbeat();

                        return $existingUsage;
                    }

                    // If global scope and different license, throw error
                    if ($license->getUniqueUsageScope() === 'global') {
                        throw new \RuntimeException('Fingerprint already in use globally');
                    }
                }

                if (! $this->canRegister($license, $fingerprint)) {
                    // Check if it's a global scope conflict
                    if ($license->getUniqueUsageScope() === 'global') {
                        $existingGlobal = LicenseUsage::forFingerprint($fingerprint)
                            ->active()
                            ->where('license_id', '!=', $license->id)
                            ->first();

                        if ($existingGlobal) {
                            throw new \RuntimeException('Fingerprint already in use globally');
                        }
                    }

                    $policy = $license->getOverLimitPolicy();

                    if ($policy === OverLimitPolicy::Reject) {
                        event(new UsageLimitReached($license, $fingerprint, $metadata));

                        // Store details for audit log (will be created after transaction rollback)
                        $shouldLogLimitReached = true;
                        $auditData = [
                            'license_id' => $license->id,
                            'license_class' => get_class($license),
                            'fingerprint' => $fingerprint,
                        ];

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
        } catch (\Exception $e) {
            if ($shouldLogLimitReached && config('licensing.audit.enabled', true)) {
                LicensingAuditLog::create([
                    'event_type' => AuditEventType::UsageLimitReached,
                    'auditable_type' => $auditData['license_class'],
                    'auditable_id' => $auditData['license_id'],
                    'meta' => [
                        'fingerprint' => $auditData['fingerprint'],
                    ],
                ]);
            }
            throw $e;
        }
    }

    public function heartbeat(LicenseUsage $usage): void
    {
        if (! $usage->isActive()) {
            throw new \RuntimeException('Cannot heartbeat revoked usage');
        }

        $usage->heartbeat();
    }

    public function revoke(LicenseUsage $usage, ?string $reason = null): void
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
            // If it's global scope and the usage belongs to a different license, can't register
            if ($license->getUniqueUsageScope() === 'global' &&
                $existingUsage->license_id !== $license->id) {
                return false;
            }

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
