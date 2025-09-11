<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Events\UsageRevoked;

class LicenseUsage extends Model
{
    protected $fillable = [
        'license_id',
        'usage_fingerprint',
        'status',
        'registered_at',
        'last_seen_at',
        'revoked_at',
        'client_type',
        'name',
        'ip',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'status' => UsageStatus::class,
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => AsArrayObject::class,
    ];

    protected $attributes = [
        'status' => UsageStatus::Active,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $usage) {
            if (! $usage->registered_at) {
                $usage->registered_at = now();
            }
            if (! $usage->last_seen_at) {
                $usage->last_seen_at = now();
            }
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(config('licensing.models.license'));
    }

    public function heartbeat(): self
    {
        $this->update(['last_seen_at' => now()]);
        return $this;
    }

    public function revoke(string $reason = null): self
    {
        if ($this->status === UsageStatus::Revoked) {
            return $this;
        }

        $updateData = [
            'status' => UsageStatus::Revoked,
            'revoked_at' => now(),
        ];
        
        if ($reason) {
            $meta = $this->meta ?? [];
            $meta['revocation_reason'] = $reason;
            $updateData['meta'] = $meta;
        }
        
        $this->update($updateData);

        event(new UsageRevoked($this, $reason));

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isStale(): bool
    {
        $inactivityDays = $this->license->getInactivityAutoRevokeDays();
        
        if ($inactivityDays === null) {
            return false;
        }

        return $this->last_seen_at->addDays($inactivityDays)->isPast();
    }

    public function getDaysSinceLastSeen(): int
    {
        return $this->last_seen_at->diffInDays(now());
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', UsageStatus::Active);
    }

    #[Scope]
    protected function revoked(Builder $query): void
    {
        $query->where('status', UsageStatus::Revoked);
    }

    #[Scope]
    protected function stale(Builder $query, int $days): void
    {
        $query->where('last_seen_at', '<', now()->subDays($days));
    }

    #[Scope]
    protected function forFingerprint(Builder $query, string $fingerprint): void
    {
        $query->where('usage_fingerprint', $fingerprint);
    }
}