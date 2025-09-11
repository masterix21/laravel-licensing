<?php

namespace LucaLongo\Licensing\Observers;

use LucaLongo\Licensing\Models\LicensingAuditLog;

class LicensingAuditLogObserver
{
    /**
     * Prevent updates - audit logs are append-only
     */
    public function updating(LicensingAuditLog $log): void
    {
        throw new \RuntimeException('Audit logs are append-only and cannot be updated');
    }

    /**
     * Set previous hash on creation if hash chaining is enabled
     */
    public function creating(LicensingAuditLog $log): void
    {
        if (! config('licensing.audit.hash_chain', true)) {
            return;
        }

        $previous = LicensingAuditLog::latest('id')->first();
        if ($previous) {
            $log->previous_hash = $previous->calculateHash();
        }
    }
}
