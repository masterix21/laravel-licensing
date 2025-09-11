<?php

namespace LucaLongo\Licensing\Observers;

use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Enums\AuditEventType;

class LicenseUsageObserver
{
    public function created(LicenseUsage $usage): void
    {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        LicensingAuditLog::create([
            'event_type' => AuditEventType::UsageRegistered,
            'auditable_type' => get_class($usage),
            'auditable_id' => $usage->id,
            'meta' => [
                'license_id' => $usage->license_id,
                'fingerprint' => $usage->usage_fingerprint,
                'client_type' => $usage->client_type,
            ],
        ]);
    }

    public function updated(LicenseUsage $usage): void
    {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        // Check for revocation  
        if ($usage->wasChanged('status') && $usage->status === \LucaLongo\Licensing\Enums\UsageStatus::Revoked) {
            // Get the reason from meta
            $reason = $usage->meta['revocation_reason'] ?? null;
            
            LicensingAuditLog::create([
                'event_type' => AuditEventType::UsageRevoked,
                'auditable_type' => get_class($usage),
                'auditable_id' => $usage->id,
                'meta' => [
                    'reason' => $reason,
                ],
            ]);
        }
    }
}