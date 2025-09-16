<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Crypt;
use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRegeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRetrieverContract;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\OverLimitPolicy;
use LucaLongo\Licensing\Enums\TokenFormat;
use LucaLongo\Licensing\Enums\TransferStatus;
use LucaLongo\Licensing\Events\LicenseActivated;
use LucaLongo\Licensing\Events\LicenseExpired;
use LucaLongo\Licensing\Events\LicenseRenewed;

class License extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'key_hash',
        'status',
        'licensable_type',
        'licensable_id',
        'template_id',
        'license_scope_id',
        'activated_at',
        'expires_at',
        'max_usages',
        'meta',
    ];

    protected $appends = [];

    /**
     * Temporary attribute to hold the license key after creation
     * This is not persisted to the database
     */
    protected ?string $temporaryLicenseKey = null;

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

    public function trials(): HasMany
    {
        return $this->hasMany(LicenseTrial::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(LicenseTransfer::class);
    }

    public function transferHistory(): HasMany
    {
        return $this->hasMany(LicenseTransferHistory::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LicenseTemplate::class, 'template_id');
    }

    public function scope(): BelongsTo
    {
        $model = config('licensing.models.license_scope', LicenseScope::class);

        return $this->belongsTo($model, 'license_scope_id');
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
        return hash_hmac('sha256', $key, static::keySalt());
    }

    public function verifyKey(string $key): bool
    {
        return hash_equals($this->key_hash, static::hashKey($key));
    }

    protected static function keySalt(): string
    {
        $salt = config('licensing.key_salt');

        if (! $salt) {
            $salt = config('app.key');
        }

        if (! $salt) {
            throw new \RuntimeException('Licensing key salt is not configured');
        }

        return $salt;
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

    public function hasFeature(string $feature): bool
    {
        if (! $this->template) {
            return false;
        }

        return $this->template->hasFeature($feature);
    }

    public function getEntitlement(string $key): mixed
    {
        if (! $this->template) {
            return null;
        }

        return $this->template->getEntitlement($key);
    }

    public function getFeatures(): array
    {
        if (! $this->template) {
            return [];
        }

        return $this->template->resolveFeatures();
    }

    public function getEntitlements(): array
    {
        if (! $this->template) {
            return [];
        }

        return $this->template->resolveEntitlements();
    }

    public static function createFromTemplate(string|LicenseTemplate $template, array $attributes = []): self
    {
        if (is_string($template)) {
            $template = LicenseTemplate::findBySlug($template);

            if (! $template) {
                throw new \InvalidArgumentException("Template not found: {$template}");
            }
        }

        $config = $template->resolveConfiguration();

        $defaultAttributes = [
            'template_id' => $template->id,
            'max_usages' => $config['max_usages'] ?? 1,
            'meta' => array_merge(
                $config,
                $attributes['meta'] ?? []
            ),
        ];

        if (isset($config['validity_days'])) {
            $defaultAttributes['expires_at'] = now()->addDays($config['validity_days']);
        }

        return static::create(array_merge($defaultAttributes, $attributes));
    }

    public function hasPendingTransfers(): bool
    {
        return $this->transfers()
            ->where('status', TransferStatus::Pending)
            ->exists();
    }

    public function getLatestTransfer(): ?LicenseTransfer
    {
        return $this->transfers()->latest()->first();
    }

    public function isTransferable(): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        if ($this->hasPendingTransfers()) {
            return false;
        }

        return true;
    }

    public function initiateTransfer(array $data): LicenseTransfer
    {
        if (! $this->isTransferable()) {
            throw new \RuntimeException('License is not transferable in its current state');
        }

        return $this->transfers()->create($data);
    }

    /**
     * Generate a new license key using the configured generator service.
     */
    public static function generateKey(): string
    {
        return app(LicenseKeyGeneratorContract::class)->generate();
    }

    /**
     * Retrieve the license key if available.
     */
    public function retrieveKey(): ?string
    {
        $retriever = app(LicenseKeyRetrieverContract::class);

        if (! $retriever->isAvailable()) {
            return null;
        }

        return $retriever->retrieve($this);
    }

    /**
     * Regenerate the license key.
     *
     * @return string The new license key
     */
    public function regenerateKey(): string
    {
        $regenerator = app(LicenseKeyRegeneratorContract::class);

        if (! $regenerator->isAvailable()) {
            throw new \RuntimeException('License key regeneration is not available');
        }

        return $regenerator->regenerate($this);
    }

    /**
     * Create a new license with an encrypted key stored.
     *
     * @return static
     */
    public static function createWithKey(array $attributes = [], ?string $providedKey = null): self
    {
        $key = $providedKey ?? static::generateKey();

        // Add encrypted key to meta
        $meta = $attributes['meta'] ?? [];
        if (config('licensing.key_management.retrieval_enabled', true)) {
            $meta['encrypted_key'] = Crypt::encryptString($key);
        }

        $attributes['key_hash'] = static::hashKey($key);
        $attributes['meta'] = $meta;

        $license = static::create($attributes);

        // Store the key temporarily (not persisted to database)
        $license->temporaryLicenseKey = $key;

        return $license;
    }

    /**
     * Get the temporary license key (only available after creation).
     */
    public function getLicenseKeyAttribute(): ?string
    {
        return $this->temporaryLicenseKey;
    }

    /**
     * Check if key retrieval is available.
     */
    public function canRetrieveKey(): bool
    {
        return app(LicenseKeyRetrieverContract::class)->isAvailable();
    }

    /**
     * Check if key regeneration is available.
     */
    public function canRegenerateKey(): bool
    {
        return app(LicenseKeyRegeneratorContract::class)->isAvailable();
    }
}
