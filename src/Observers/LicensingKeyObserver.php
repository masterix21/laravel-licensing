<?php

namespace LucaLongo\Licensing\Observers;

use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Enums\KeyType;

class LicensingKeyObserver
{
    public function created(LicensingKey $key): void
    {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        $eventType = $key->type === KeyType::Root 
            ? AuditEventType::KeyRootGenerated 
            : AuditEventType::KeySigningIssued;

        LicensingAuditLog::create([
            'event_type' => $eventType,
            'auditable_type' => get_class($key),
            'auditable_id' => $key->id,
            'meta' => [
                'kid' => $key->kid,
                'type' => $key->type->value,
                'algorithm' => $key->algorithm,
            ],
        ]);
    }

    public function updated(LicensingKey $key): void
    {
        if (! config('licensing.audit.enabled', true)) {
            return;
        }

        // Check for revocation
        if ($key->wasChanged('status') && $key->status->value === 'revoked') {
            LicensingAuditLog::create([
                'event_type' => AuditEventType::KeyRevoked,
                'auditable_type' => get_class($key),
                'auditable_id' => $key->id,
                'meta' => [
                    'kid' => $key->kid,
                    'reason' => $key->revocation_reason,
                ],
            ]);
        }
    }
}