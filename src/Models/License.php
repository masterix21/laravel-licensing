<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\OverLimitPolicy;
use LucaLongo\Licensing\Enums\TokenFormat;
use LucaLongo\Licensing\Events\LicenseActivated;
use LucaLongo\Licensing\Events\LicenseExpired;
use LucaLongo\Licensing\Events\LicenseRenewed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class License extends Model
{
    use HasUlids;
    protected $fillable = [
        'key_hash',
        'status',
        'licensable_type',
        'licensable_id',
        'activated_at',
        'expires_at',
        'max_usages',
        'meta',
    ];

    protected $casts = [
        'status' => LicenseStatus::class,
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_usages' => 'integer',
        'meta' => AsArrayObject::class,
    ];

    protected $attributes = [
        'status' => LicenseStatus::Pending,
        'max_usages' => 1,
    ];

    public function uniqueIds(): array
    {
        return ['uid'];
    }

    public function licensable(): MorphTo
    {
        return $this->morphTo();
    }

    public function usages(): HasMany
    {
        return $this->hasMany(config('licensing.models.license_usage'));
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(config('licensing.models.license_renewal'));
    }

    public function activeUsages(): HasMany
    {
        return $this->usages()->where('status', 'active');
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key_hash', static::hashKey($key))->first();
    }

    public static function findByUid(string $uid): ?self
    {
        return static::where('uid', $uid)->first();
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', config('app.key').$key);
    }

    public function verifyKey(string $key): bool
    {
        return hash_equals($this->key_hash, static::hashKey($key));
    }

    public function activate(): self
    {
        if (! $this->status->canActivate()) {
            throw new \RuntimeException('License cannot be activated in current status: '.$this->status->value);
        }

        $this->update([
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);

        event(new LicenseActivated($this));

        return $this;
    }

    public function renew(\DateTimeInterface $expiresAt, array $renewalData = []): self
    {
        if (! $this->status->canRenew()) {
            throw new \RuntimeException('License cannot be renewed in current status: '.$this->status->value);
        }

        $oldExpiresAt = $this->expires_at;

        $this->update([
            'expires_at' => $expiresAt,
            'status' => LicenseStatus::Active,
        ]);

        $this->renewals()->create([
            'period_start' => $oldExpiresAt ?? now(),
            'period_end' => $expiresAt,
            ...$renewalData,
        ]);

        event(new LicenseRenewed($this));

        return $this;
    }

    public function suspend(): self
    {
        $this->update(['status' => LicenseStatus::Suspended]);

        return $this;
    }

    public function cancel(): self
    {
        $this->update(['status' => LicenseStatus::Cancelled]);

        return $this;
    }

    public function transitionToGrace(): self
    {
        if ($this->status === LicenseStatus::Active && $this->isExpired()) {
            $this->update(['status' => LicenseStatus::Grace]);
        }

        return $this;
    }

    public function transitionToExpired(): self
    {
        if ($this->isInGracePeriod() && $this->gracePeriodExpired()) {
            $this->update(['status' => LicenseStatus::Expired]);
            event(new LicenseExpired($this));
        }

        return $this;
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isInGracePeriod(): bool
    {
        return $this->status === LicenseStatus::Grace;
    }

    public function gracePeriodExpired(): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        $graceDays = $this->getPolicy('grace_days');

        return $this->expires_at->addDays($graceDays)->isPast();
    }

    public function daysUntilExpiration(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    public function hasAvailableSeats(): bool
    {
        return $this->activeUsages()->count() < $this->max_usages;
    }

    public function getAvailableSeats(): int
    {
        return max(0, $this->max_usages - $this->activeUsages()->count());
    }

    public function getPolicy(string $key): mixed
    {
        return $this->meta['policies'][$key]
            ?? config("licensing.policies.{$key}");
    }

    public function getOverLimitPolicy(): OverLimitPolicy
    {
        $value = $this->getPolicy('over_limit');

        return OverLimitPolicy::from($value);
    }

    public function getGraceDays(): int
    {
        return (int) $this->getPolicy('grace_days');
    }

    public function getInactivityAutoRevokeDays(): ?int
    {
        $days = $this->getPolicy('usage_inactivity_auto_revoke_days');

        return $days !== null ? (int) $days : null;
    }

    public function getUniqueUsageScope(): string
    {
        return $this->getPolicy('unique_usage_scope');
    }

    public function getOfflineTokenConfig(string $key): mixed
    {
        return $this->meta['offline_token'][$key]
            ?? config("licensing.offline_token.{$key}");
    }

    public function isOfflineTokenEnabled(): bool
    {
        return (bool) $this->getOfflineTokenConfig('enabled');
    }

    public function getTokenFormat(): TokenFormat
    {
        $format = $this->getOfflineTokenConfig('format');

        return TokenFormat::from($format);
    }

    public function getTokenTtlDays(): int
    {
        return (int) $this->getOfflineTokenConfig('ttl_days');
    }

    public function getForceOnlineAfterDays(): int
    {
        return (int) $this->getOfflineTokenConfig('force_online_after_days');
    }

    public function getClockSkewSeconds(): int
    {
        return (int) $this->getOfflineTokenConfig('clock_skew_seconds');
    }
}
