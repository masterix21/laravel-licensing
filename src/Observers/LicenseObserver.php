<?php

namespace LucaLongo\Licensing\Observers;

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Observers\Concerns\TracksActor;

class LicenseObserver
{
    use TracksActor;
    
    public function created(License $license): void
    {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        LicensingAuditLog::create($this->withActorData([
            'event_type' => AuditEventType::LicenseCreated,
            'auditable_type' => get_class($license),
            'auditable_id' => $license->id,
            'meta' => [
                'license_id' => $license->id,
                'status' => $license->status->value,
                'max_usages' => $license->max_usages,
            ],
        ]));
    }

    public function updated(License $license): void
    {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        // Check for activation
        if ($license->wasChanged('status') && $license->status->value === 'active' && $license->getOriginal('status')?->value === 'pending') {
            LicensingAuditLog::create($this->withActorData([
                'event_type' => AuditEventType::LicenseActivated,
                'auditable_type' => get_class($license),
                'auditable_id' => $license->id,
                'meta' => [
                    'activated_at' => $license->activated_at?->toIso8601String(),
                ],
            ]));
        }

        // Check for renewal (expires_at changed and it's not the first time it's being set)
        if ($license->wasChanged('expires_at') && $license->exists) {
            $oldExpiresAt = $license->getOriginal('expires_at');
            // Only log as renewal if there was a previous expires_at value and not just created
            if ($oldExpiresAt !== null && !$license->wasRecentlyCreated) {
                LicensingAuditLog::create($this->withActorData([
                    'event_type' => AuditEventType::LicenseRenewed,
                    'auditable_type' => get_class($license),
                    'auditable_id' => $license->id,
                    'meta' => [
                        'old_expires_at' => $oldExpiresAt instanceof \DateTimeInterface ? $oldExpiresAt->toIso8601String() : $oldExpiresAt,
                        'new_expires_at' => $license->expires_at?->toIso8601String(),
                    ],
                ]));
            }
        }

        // Check for expiration
        if ($license->wasChanged('status') && $license->status->value === 'expired') {
            LicensingAuditLog::create($this->withActorData([
                'event_type' => AuditEventType::LicenseExpired,
                'auditable_type' => get_class($license),
                'auditable_id' => $license->id,
                'meta' => [
                    'expired_at' => now()->toIso8601String(),
                ],
            ]));
        }
    }
}