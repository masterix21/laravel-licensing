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
        'group',
        'name',
        'tier_level',
        'parent_template_id',
        'base_configuration',
        'features',
        'entitlements',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'tier_level' => 'integer',
        'base_configuration' => AsArrayObject::class,
        'features' => AsArrayObject::class,
        'entitlements' => AsArrayObject::class,
        'is_active' => 'boolean',
        'meta' => AsArrayObject::class,
    ];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['group', 'name'])
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
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
    public function byGroup(Builder $query, string $group): void
    {
        $query->where('group', $group);
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

    public static function getForGroup(string $group): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->active()
            ->byGroup($group)
            ->orderedByTier()
            ->get();
    }
}
