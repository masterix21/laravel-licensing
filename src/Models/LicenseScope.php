<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LicenseScope extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'identifier',
        'description',
        'is_active',
        'key_rotation_days',
        'last_key_rotation_at',
        'next_key_rotation_at',
        'default_max_usages',
        'default_duration_days',
        'default_grace_days',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'key_rotation_days' => 'integer',
        'last_key_rotation_at' => 'datetime',
        'next_key_rotation_at' => 'datetime',
        'default_max_usages' => 'integer',
        'default_duration_days' => 'integer',
        'default_grace_days' => 'integer',
        'meta' => AsArrayObject::class,
    ];

    protected $attributes = [
        'is_active' => true,
        'key_rotation_days' => 90,
        'default_max_usages' => 1,
        'default_grace_days' => 14,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $scope) {
            if (! $scope->slug) {
                $scope->slug = Str::slug($scope->name);
            }

            if (! $scope->identifier) {
                $scope->identifier = 'com.example.'.$scope->slug;
            }

            // Set next rotation date if rotation is enabled
            if ($scope->key_rotation_days > 0 && ! $scope->next_key_rotation_at) {
                $scope->next_key_rotation_at = now()->addDays($scope->key_rotation_days);
            }
        });
    }

    /**
     * Get licenses belonging to this scope
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * Get signing keys for this scope
     */
    public function signingKeys(): HasMany
    {
        return $this->hasMany(LicensingKey::class)
            ->where('type', 'signing');
    }

    /**
     * Get active signing key for this scope
     */
    public function activeSigningKey(): ?LicensingKey
    {
        return $this->signingKeys()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Check if scope needs key rotation
     */
    public function needsKeyRotation(): bool
    {
        if ($this->key_rotation_days <= 0) {
            return false;
        }

        if (! $this->next_key_rotation_at) {
            return true;
        }

        return $this->next_key_rotation_at->isPast();
    }

    /**
     * Rotate signing keys for this scope
     */
    public function rotateKeys(string $reason = 'Scheduled rotation'): LicensingKey
    {
        // Revoke current active keys
        $this->signingKeys()
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

        // Create new signing key
        $newKey = LicensingKey::generateSigningKey(
            kid: $this->slug.'-'.now()->format('Y-m-d'),
            scope: $this
        );
        $newKey->save();

        // Update rotation timestamps
        $this->update([
            'last_key_rotation_at' => now(),
            'next_key_rotation_at' => now()->addDays($this->key_rotation_days),
        ]);

        return $newKey;
    }

    /**
     * Get default license attributes for this scope
     */
    public function getDefaultLicenseAttributes(): array
    {
        return [
            'max_usages' => $this->default_max_usages,
            'expires_at' => $this->default_duration_days
                ? now()->addDays($this->default_duration_days)
                : null,
            'meta' => array_merge(
                $this->meta?->toArray() ?? [],
                [
                    'scope' => $this->slug,
                    'scope_name' => $this->name,
                ]
            ),
        ];
    }

    /**
     * Find scope by slug or identifier
     */
    public static function findBySlugOrIdentifier(string $value): ?self
    {
        return static::where('slug', $value)
            ->orWhere('identifier', $value)
            ->first();
    }

    /**
     * Get or create global scope
     */
    public static function global(): self
    {
        return static::firstOrCreate(
            ['slug' => 'global'],
            [
                'name' => 'Global',
                'identifier' => 'global',
                'description' => 'Global scope for licenses without specific scope',
                'key_rotation_days' => 90,
            ]
        );
    }

    /**
     * Scope query for active scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query for scopes needing rotation
     */
    public function scopeNeedingRotation($query)
    {
        return $query->where('key_rotation_days', '>', 0)
            ->where(function ($q) {
                $q->whereNull('next_key_rotation_at')
                    ->orWhere('next_key_rotation_at', '<=', now());
            });
    }
}
