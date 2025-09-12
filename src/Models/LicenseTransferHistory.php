<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LicenseTransferHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_id',
        'transfer_id',
        'previous_licensable_type',
        'previous_licensable_id',
        'new_licensable_type',
        'new_licensable_id',
        'previous_snapshot',
        'new_snapshot',
        'transfer_type',
        'executed_by_type',
        'executed_by_id',
        'usages_preserved',
        'expiration_preserved',
        'activation_reset',
        'usages_transferred_count',
        'usages_revoked_count',
        'integrity_hash',
        'executed_at',
    ];

    protected $casts = [
        'previous_snapshot' => 'array',
        'new_snapshot' => 'array',
        'usages_preserved' => 'boolean',
        'expiration_preserved' => 'boolean',
        'activation_reset' => 'boolean',
        'executed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $history) {
            $history->integrity_hash = $history->calculateIntegrityHash();
            
            if (! $history->executed_at) {
                $history->executed_at = now();
            }
        });

        static::updating(function () {
            throw new \RuntimeException('Transfer history records are immutable');
        });

        static::deleting(function () {
            throw new \RuntimeException('Transfer history records cannot be deleted');
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(LicenseTransfer::class, 'transfer_id');
    }

    public function previousLicensable(): MorphTo
    {
        return $this->morphTo('previous_licensable');
    }

    public function newLicensable(): MorphTo
    {
        return $this->morphTo('new_licensable');
    }

    public function executedBy(): MorphTo
    {
        return $this->morphTo('executed_by');
    }

    public function scopeForLicense($query, $licenseId)
    {
        return $query->where('license_id', $licenseId);
    }

    public function scopeInvolvedUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where(function ($sub) use ($userId) {
                $sub->where('previous_licensable_type', 'App\Models\User')
                    ->where('previous_licensable_id', $userId);
            })->orWhere(function ($sub) use ($userId) {
                $sub->where('new_licensable_type', 'App\Models\User')
                    ->where('new_licensable_id', $userId);
            });
        });
    }

    public function scopeInvolvedOrganization($query, $organizationId)
    {
        return $query->where(function ($q) use ($organizationId) {
            $q->where(function ($sub) use ($organizationId) {
                $sub->where('previous_licensable_type', 'App\Models\Organization')
                    ->where('previous_licensable_id', $organizationId);
            })->orWhere(function ($sub) use ($organizationId) {
                $sub->where('new_licensable_type', 'App\Models\Organization')
                    ->where('new_licensable_id', $organizationId);
            });
        });
    }

    public function verifyIntegrity(): bool
    {
        if (!$this->integrity_hash) {
            return false;
        }
        
        return hash_equals($this->integrity_hash, $this->calculateIntegrityHash());
    }

    protected function calculateIntegrityHash(): string
    {
        // Don't include the ID in the hash calculation since it doesn't exist during creation
        $data = [
            'license_id' => $this->license_id,
            'transfer_id' => $this->transfer_id,
            'previous_licensable' => $this->previous_licensable_type.':'.$this->previous_licensable_id,
            'new_licensable' => $this->new_licensable_type.':'.$this->new_licensable_id,
            'previous_snapshot' => json_encode($this->previous_snapshot),
            'new_snapshot' => json_encode($this->new_snapshot),
            'transfer_type' => $this->transfer_type,
            'executed_by' => $this->executed_by_type.':'.$this->executed_by_id,
            'usages_preserved' => (bool) $this->usages_preserved,
            'expiration_preserved' => (bool) $this->expiration_preserved,
            'activation_reset' => (bool) $this->activation_reset,
            'usages_transferred_count' => (int) $this->usages_transferred_count,
            'usages_revoked_count' => (int) $this->usages_revoked_count,
            'executed_at' => $this->executed_at?->toISOString(),
        ];

        return hash('sha256', json_encode($data));
    }

    public function getDiffSummary(): array
    {
        $changes = [];

        if ($this->previous_licensable_type !== $this->new_licensable_type ||
            $this->previous_licensable_id !== $this->new_licensable_id) {
            $changes['owner'] = [
                'from' => $this->previous_licensable_type.':'.$this->previous_licensable_id,
                'to' => $this->new_licensable_type.':'.$this->new_licensable_id,
            ];
        }

        if (! $this->usages_preserved) {
            $changes['usages'] = [
                'revoked' => $this->usages_revoked_count,
                'transferred' => $this->usages_transferred_count,
            ];
        }

        if ($this->activation_reset) {
            $changes['activation'] = 'reset';
        }

        if (! $this->expiration_preserved) {
            $changes['expiration'] = [
                'from' => $this->previous_snapshot['expires_at'] ?? null,
                'to' => $this->new_snapshot['expires_at'] ?? null,
            ];
        }

        return $changes;
    }
}