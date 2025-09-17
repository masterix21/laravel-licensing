<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class LicenseTemplate extends Model
{
    use HasFactory, HasSlug, HasUlids;

    protected $fillable = [
        'license_scope_id',
        'name',
        'tier_level',
        'parent_template_id',
        'base_configuration',
        'features',
        'entitlements',
        'is_active',
        'meta',
        'supports_trial',
        'trial_duration_days',
        'has_grace_period',
        'grace_period_days',
        'license_duration_days',
    ];

    protected $casts = [
        'tier_level' => 'integer',
        'base_configuration' => AsArrayObject::class,
        'features' => AsArrayObject::class,
        'entitlements' => AsArrayObject::class,
        'is_active' => 'boolean',
        'meta' => AsArrayObject::class,
        'supports_trial' => 'boolean',
        'trial_duration_days' => 'integer',
        'has_grace_period' => 'boolean',
        'grace_period_days' => 'integer',
        'license_duration_days' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(function (self $model) {
                $scopePart = $model->license_scope_id
                    ? 'scope-'.$model->license_scope_id
                    : 'global';

                return $scopePart.' '.$model->name;
            })
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(LicenseScope::class, 'license_scope_id');
    }

    public function parentTemplate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_template_id');
    }

    public function childTemplates(): HasMany
    {
        return $this->hasMany(self::class, 'parent_template_id');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(config('licensing.models.license'));
    }

    public function resolveConfiguration(): array
    {
        $config = $this->base_configuration ? $this->base_configuration->toArray() : [];

        if ($this->parent_template_id && $this->parentTemplate) {
            $parentConfig = $this->parentTemplate->resolveConfiguration();
            $config = array_merge_recursive($parentConfig, $config);
        }

        return $config;
    }

    public function resolveFeatures(): array
    {
        $features = $this->features ? $this->features->toArray() : [];

        if ($this->parent_template_id && $this->parentTemplate) {
            $parentFeatures = $this->parentTemplate->resolveFeatures();
            $features = array_merge($parentFeatures, $features);
        }

        return $features;
    }

    public function resolveEntitlements(): array
    {
        $entitlements = $this->entitlements ? $this->entitlements->toArray() : [];

        if ($this->parent_template_id && $this->parentTemplate) {
            $parentEntitlements = $this->parentTemplate->resolveEntitlements();
            $entitlements = array_merge($parentEntitlements, $entitlements);
        }

        return $entitlements;
    }

    public function hasFeature(string $feature): bool
    {
        $features = $this->resolveFeatures();

        return isset($features[$feature]) && $features[$feature] === true;
    }

    public function getEntitlement(string $key): mixed
    {
        $entitlements = $this->resolveEntitlements();

        return $entitlements[$key] ?? null;
    }

    public function supportsTrial(): bool
    {
        if ($this->hasConfiguredValue('supports_trial')) {
            return (bool) $this->supports_trial;
        }

        if ($this->shouldInheritFromParent()) {
            return $this->parentTemplate->supportsTrial();
        }

        return false;
    }

    public function getTrialDurationDays(): ?int
    {
        if (! $this->supportsTrial()) {
            return null;
        }

        if ($this->hasConfiguredValue('trial_duration_days')) {
            return $this->trial_duration_days;
        }

        if ($this->shouldInheritFromParent()) {
            return $this->parentTemplate->getTrialDurationDays();
        }

        return config('licensing.trials.default_duration_days');
    }

    public function hasGracePeriod(): bool
    {
        if ($this->hasConfiguredValue('has_grace_period')) {
            return (bool) $this->has_grace_period;
        }

        if ($this->shouldInheritFromParent()) {
            return $this->parentTemplate->hasGracePeriod();
        }

        $config = $this->resolveConfiguration();

        return isset($config['grace_days']) && (int) $config['grace_days'] > 0;
    }

    public function getGracePeriodDays(): ?int
    {
        if (! $this->hasGracePeriod()) {
            return null;
        }

        if ($this->hasConfiguredValue('grace_period_days')) {
            return $this->grace_period_days;
        }

        if ($this->shouldInheritFromParent()) {
            return $this->parentTemplate->getGracePeriodDays();
        }

        $config = $this->resolveConfiguration();

        return $config['grace_days'] ?? config('licensing.policies.grace_days');
    }

    public function getLicenseDurationDays(): ?int
    {
        if ($this->hasConfiguredValue('license_duration_days')) {
            return $this->license_duration_days;
        }

        if ($this->shouldInheritFromParent()) {
            return $this->parentTemplate->getLicenseDurationDays();
        }

        $config = $this->resolveConfiguration();

        return $config['validity_days'] ?? null;
    }

    public function isHigherTierThan(self $otherTemplate): bool
    {
        return $this->tier_level > $otherTemplate->tier_level;
    }

    public function isLowerTierThan(self $otherTemplate): bool
    {
        return $this->tier_level < $otherTemplate->tier_level;
    }

    public function isSameTierAs(self $otherTemplate): bool
    {
        return $this->tier_level === $otherTemplate->tier_level;
    }

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    public function byTierLevel(Builder $query, int $level): void
    {
        $query->where('tier_level', $level);
    }

    #[Scope]
    public function orderedByTier(Builder $query, string $direction = 'asc'): void
    {
        $query->orderBy('tier_level', $direction);
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function getForScope(?LicenseScope $scope): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->active()
            ->where('license_scope_id', optional($scope)->getKey())
            ->orderedByTier()
            ->get();
    }

    protected function shouldInheritFromParent(): bool
    {
        if (! config('licensing.templates.allow_inheritance', true)) {
            return false;
        }

        if (! $this->parent_template_id) {
            return false;
        }

        return $this->parentTemplate !== null;
    }

    protected function hasConfiguredValue(string $attribute): bool
    {
        return array_key_exists($attribute, $this->getAttributes())
            && $this->getRawOriginal($attribute) !== null;
    }
}
