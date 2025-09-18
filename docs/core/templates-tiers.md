# Templates & Tiers

The Templates & Tiers system provides a structured approach to defining license configurations, features, and pricing tiers. Templates serve as blueprints for licenses, allowing you to standardize licensing policies and entitlements across different plans and customer segments.

## Table of Contents

- [Overview](#overview)
- [Template Structure](#template-structure)
- [Tier Hierarchy](#tier-hierarchy)
- [Template Inheritance](#template-inheritance)
- [Features & Entitlements](#features--entitlements)
- [Configuration Resolution](#configuration-resolution)
- [Creating Templates](#creating-templates)
- [Using Templates](#using-templates)
- [Template Management](#template-management)
- [Best Practices](#best-practices)

## Overview

License Templates define reusable configurations for different license types, plans, or customer segments. They support hierarchical inheritance, allowing you to create base templates and extend them for specific use cases.

```php
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicenseTemplate;

// Create a base template
$scope = LicenseScope::firstOrCreate(['slug' => 'saas-app'], ['name' => 'SaaS App']);

$basicPlan = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Basic Plan',
    'tier_level' => 1,
    'trial_days' => 7,          // 7-day trial period
    'duration_days' => 365,     // 1-year duration after activation
    'base_configuration' => [
        'max_usages' => 1,
        'validity_days' => 365,  // Can override duration_days if needed
    ],
    'features' => [
        'core_features' => true,
        'basic_support' => true,
    ],
    'entitlements' => [
        'api_calls_per_month' => 10000,
        'storage_gb' => 10,
    ],
]);

// Create a license from template - trial and duration are automatically applied
$license = License::createFromTemplate($basicPlan->slug, [
    'licensable_type' => 'App\Models\User',
    'licensable_id' => $user->id,
    'key_hash' => License::hashKey($generatedKey),
]);
```

## Template Structure

### Core Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | ULID | Primary key |
| `ulid` | ULID | Public-facing unique identifier |
| `license_scope_id` | Nullable Integer | Owning scope ID (`null` for global templates) |
| `name` | String | Human-readable template name |
| `slug` | String | URL-friendly identifier (auto-generated) |
| `tier_level` | Integer | Numeric tier level for hierarchy |
| `parent_template_id` | ULID | Parent template for inheritance |
| `trial_days` | Nullable Integer | Trial period duration in days |
| `duration_days` | Nullable Integer | License duration in days from activation |
| `base_configuration` | JSON | License configuration defaults |
| `features` | JSON | Available features |
| `entitlements` | JSON | Usage limits and quotas |
| `is_active` | Boolean | Whether template is available for use |
| `meta` | JSON | Additional metadata |

### Configuration Structure

```php
$template = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Professional Plan',
    'tier_level' => 2,
    'trial_days' => 14,         // 14-day trial
    'duration_days' => 365,     // 1-year subscription
    'base_configuration' => [
        'max_usages' => 5,
        'validity_days' => 365,
        'policies' => [
            'grace_days' => 14,
            'over_limit' => 'reject',
            'usage_inactivity_auto_revoke_days' => 30,
        ],
        'offline_token' => [
            'enabled' => true,
            'ttl_days' => 7,
            'force_online_after_days' => 14,
        ],
    ],
    'features' => [
        'core_features' => true,
        'advanced_reporting' => true,
        'api_access' => true,
        'priority_support' => true,
        'custom_integrations' => false,
    ],
    'entitlements' => [
        'api_calls_per_month' => 100000,
        'storage_gb' => 100,
        'team_members' => 10,
        'projects' => 50,
        'custom_fields' => 25,
    ],
    'meta' => [
        'display_name' => 'Professional',
        'description' => 'Perfect for growing teams and businesses',
        'pricing' => [
            'monthly' => 4900, // $49.00 in cents
            'annually' => 49000, // $490.00 in cents (2 months free)
        ],
        'popular' => true,
    ],
]);
```

## Tier Hierarchy

Templates support numeric tier levels for hierarchical organization:

```php
// Tier structure example
$templates = [
    ['name' => 'Free', 'tier_level' => 0],
    ['name' => 'Basic', 'tier_level' => 1], 
    ['name' => 'Professional', 'tier_level' => 2],
    ['name' => 'Enterprise', 'tier_level' => 3],
    ['name' => 'Custom', 'tier_level' => 9999],
];

// Query by tier level
$basicTier = LicenseTemplate::byTierLevel(1)->get();
$higherTiers = LicenseTemplate::where('tier_level', '>', 1)->orderedByTier()->get();

// Compare tiers
$pro = LicenseTemplate::where('license_scope_id', $scope->id)->where('name', 'Professional')->first();
$basic = LicenseTemplate::where('license_scope_id', $scope->id)->where('name', 'Basic')->first();

if ($pro->isHigherTierThan($basic)) {
    echo "Professional offers more features than Basic";
}
```

### Tier-Based Upgrades

```php
class LicenseUpgradeService
{
    public function canUpgrade(License $currentLicense, LicenseTemplate $targetTemplate): bool
    {
        if (!$currentLicense->template) {
            return true; // Can upgrade from templateless license
        }
        
        // Only allow upgrades to higher tiers
        return $targetTemplate->isHigherTierThan($currentLicense->template);
    }
    
    public function getAvailableUpgrades(License $license): Collection
    {
        if (!$license->template) {
            return collect();
        }
        
        return LicenseTemplate::active()
            ->where('license_scope_id', $license->template->license_scope_id)
            ->where('tier_level', '>', $license->template->tier_level)
            ->orderedByTier()
            ->get();
    }
}
```

## Trial and Duration Management

Templates support automatic trial periods and license duration management:

### Trial Periods

```php
// Template with 14-day trial
$template = LicenseTemplate::create([
    'name' => 'Pro Plan',
    'trial_days' => 14,  // Automatic 14-day trial
    'duration_days' => 365,
]);

// Create license with trial
$license = License::createWithKey([
    'license_template_id' => $template->id,
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
]);

// Trial expiration is automatically calculated
$trialEndsAt = $license->activated_at->addDays($template->trial_days);

// Check if in trial
if ($license->isInTrial()) {
    $daysRemaining = $license->trialDaysRemaining();
    // Show trial banner
}
```

### Duration Management

```php
// Monthly subscription template
$monthly = LicenseTemplate::create([
    'name' => 'Monthly Plan',
    'trial_days' => 7,
    'duration_days' => 30,  // Auto-renews every 30 days
]);

// Annual subscription template
$annual = LicenseTemplate::create([
    'name' => 'Annual Plan',
    'trial_days' => 14,
    'duration_days' => 365,  // 1-year license
]);

// Lifetime license (no automatic expiration)
$lifetime = LicenseTemplate::create([
    'name' => 'Lifetime License',
    'trial_days' => null,     // No trial
    'duration_days' => null,  // Never expires automatically
]);
```

### Flexible Configuration

```php
// Override template values per license
$license = License::createWithKey([
    'license_template_id' => $monthly->id,
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    'expires_at' => now()->addMonths(3),  // Override template duration
]);

// Template with configurable trial
$enterprise = LicenseTemplate::create([
    'name' => 'Enterprise',
    'trial_days' => null,  // Trial configured per customer
    'duration_days' => null,  // Duration negotiated
    'meta' => [
        'custom_pricing' => true,
        'negotiable_terms' => true,
    ],
]);
```

### Working with Trial and Duration

```php
// Access template settings
$template = $license->template;
$trialDays = $template->trial_days;
$durationDays = $template->duration_days;

// Calculate dates
if ($template->trial_days) {
    $trialEnd = $license->activated_at->addDays($template->trial_days);
}

if ($template->duration_days) {
    $expiresAt = $license->activated_at->addDays($template->duration_days);
}

// Query licenses by trial status
$inTrial = License::whereHas('template', function($q) {
    $q->whereNotNull('trial_days');
})->where('activated_at', '>', now()->subDays(14))->get();

// Find expiring licenses
$expiringSoon = License::whereHas('template', function($q) {
    $q->whereNotNull('duration_days');
})->whereBetween('expires_at', [now(), now()->addDays(7)])->get();
```

## Template Inheritance

Templates can inherit configuration from parent templates:

```php
// Base template
$baseTemplate = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Base SaaS',
    'tier_level' => 0,
    'base_configuration' => [
        'policies' => [
            'grace_days' => 7,
            'over_limit' => 'reject',
        ],
    ],
    'features' => [
        'core_features' => true,
        'basic_support' => true,
    ],
    'entitlements' => [
        'storage_gb' => 1,
        'users' => 1,
    ],
]);

// Child template inherits from parent
$proTemplate = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Professional',
    'tier_level' => 2,
    'parent_template_id' => $baseTemplate->id,
    'base_configuration' => [
        'max_usages' => 5,
        'policies' => [
            'grace_days' => 14, // Overrides parent
        ],
    ],
    'features' => [
        'api_access' => true,        // Added to parent features
        'advanced_reporting' => true,
    ],
    'entitlements' => [
        'storage_gb' => 100,         // Overrides parent
        'users' => 10,              // Overrides parent
        'api_calls_per_month' => 50000, // New entitlement
    ],
]);

// Resolved configuration includes inherited values
$resolvedConfig = $proTemplate->resolveConfiguration();
$resolvedFeatures = $proTemplate->resolveFeatures();
$resolvedEntitlements = $proTemplate->resolveEntitlements();
```

### Inheritance Resolution

```php
// The resolved configuration will be:
$resolvedConfig = [
    'max_usages' => 5,              // From child
    'policies' => [
        'grace_days' => 14,         // Overridden by child
        'over_limit' => 'reject',   // From parent
    ],
];

$resolvedFeatures = [
    'core_features' => true,        // From parent
    'basic_support' => true,        // From parent  
    'api_access' => true,           // From child
    'advanced_reporting' => true,   // From child
];

$resolvedEntitlements = [
    'storage_gb' => 100,            // Overridden by child
    'users' => 10,                  // Overridden by child
    'api_calls_per_month' => 50000, // From child
];
```

## Features & Entitlements

### Features

Boolean flags that enable/disable functionality:

```php
$template->features = [
    'core_features' => true,
    'api_access' => true,
    'advanced_reporting' => true,
    'priority_support' => true,
    'custom_integrations' => false,
    'white_labeling' => false,
    'sso_integration' => false,
];

// Check features in application
if ($license->hasFeature('api_access')) {
    // Enable API endpoints
}

if ($license->hasFeature('advanced_reporting')) {
    // Show advanced report options
}
```

### Entitlements

Numeric limits and quotas:

```php
$template->entitlements = [
    'api_calls_per_month' => 100000,
    'storage_gb' => 100,
    'team_members' => 25,
    'projects' => 100,
    'custom_fields' => 50,
    'webhooks' => 10,
    'integrations' => 5,
    'data_retention_days' => 365,
];

// Check entitlements in application
$apiLimit = $license->getEntitlement('api_calls_per_month');
$storageLimit = $license->getEntitlement('storage_gb');

// Usage validation
if ($currentApiCalls >= $apiLimit) {
    throw new ApiLimitExceededException();
}
```

### Feature Gates

```php
class FeatureGate
{
    public function __construct(private License $license)
    {
    }
    
    public function allows(string $feature): bool
    {
        return $this->license->hasFeature($feature);
    }
    
    public function allowsWithEntitlement(string $feature, string $entitlement, int $current): bool
    {
        if (!$this->allows($feature)) {
            return false;
        }
        
        $limit = $this->license->getEntitlement($entitlement);
        return $limit === null || $current < $limit;
    }
    
    // Usage examples:
    // $gate->allows('api_access')
    // $gate->allowsWithEntitlement('api_access', 'api_calls_per_month', $currentCalls)
}
```

## Configuration Resolution

Templates support deep configuration merging with inheritance:

### Resolution Process

1. Start with current template configuration
2. Recursively merge parent template configurations
3. Child values override parent values
4. Arrays are merged (not replaced)

```php
class LicenseTemplate extends Model
{
    public function resolveConfiguration(): array
    {
        $config = $this->base_configuration ? $this->base_configuration->toArray() : [];

        if ($this->parent_template_id && $this->parentTemplate) {
            $parentConfig = $this->parentTemplate->resolveConfiguration();
            $config = array_merge_recursive($parentConfig, $config);
        }

        return $config;
    }
}
```

### Custom Resolution

```php
class TemplateResolver
{
    public function resolveWithDefaults(LicenseTemplate $template): array
    {
        $resolved = $template->resolveConfiguration();
        
        // Apply system defaults
        $defaults = config('licensing.template_defaults');
        
        return array_merge_recursive($defaults, $resolved);
    }
    
    public function resolveForEnvironment(LicenseTemplate $template, string $env): array
    {
        $base = $this->resolveWithDefaults($template);
        
        // Apply environment-specific overrides
        $envConfig = $base['environments'][$env] ?? [];
        
        return array_merge_recursive($base, $envConfig);
    }
}
```

## Creating Templates

### Manual Creation

```php
$template = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Startup Plan',
    'tier_level' => 1,
    'trial_days' => 14,        // 14-day trial
    'duration_days' => 365,    // 1-year subscription
    'base_configuration' => [
        'max_usages' => 3,
        'validity_days' => 365,
        'policies' => [
            'grace_days' => 14,
            'over_limit' => 'reject',
        ],
    ],
    'features' => [
        'core_features' => true,
        'basic_support' => true,
        'api_access' => true,
    ],
    'entitlements' => [
        'api_calls_per_month' => 25000,
        'storage_gb' => 25,
        'team_members' => 3,
    ],
]);
```

### Template Builder

```php
class TemplateBuilder
{
    private array $config = [];
    private array $features = [];
    private array $entitlements = [];
    
    public static function create(LicenseScope $scope, string $name, int $tierLevel): self
    {
        $builder = new self();
        $builder->config = [
            'license_scope_id' => $scope->id,
            'name' => $name,
            'tier_level' => $tierLevel,
        ];
        
        return $builder;
    }
    
    public function inheritsFrom(LicenseTemplate $parent): self
    {
        $this->config['parent_template_id'] = $parent->id;
        return $this;
    }
    
    public function withSeats(int $maxUsages): self
    {
        $this->config['base_configuration']['max_usages'] = $maxUsages;
        return $this;
    }
    
    public function validFor(int $days): self
    {
        $this->config['base_configuration']['validity_days'] = $days;
        return $this;
    }

    public function withTrialDays(int $days): self
    {
        $this->config['trial_days'] = $days;
        return $this;
    }

    public function withDurationDays(int $days): self
    {
        $this->config['duration_days'] = $days;
        return $this;
    }

    public function withGracePeriod(int $days): self
    {
        $this->config['base_configuration']['policies']['grace_days'] = $days;
        return $this;
    }
    
    public function enableFeature(string $feature): self
    {
        $this->features[$feature] = true;
        return $this;
    }
    
    public function withEntitlement(string $key, int $value): self
    {
        $this->entitlements[$key] = $value;
        return $this;
    }
    
    public function build(): LicenseTemplate
    {
        return LicenseTemplate::create(array_merge($this->config, [
            'features' => $this->features,
            'entitlements' => $this->entitlements,
        ]));
    }
}

// Usage
$scope = LicenseScope::firstOrCreate(['slug' => 'saas-app'], ['name' => 'SaaS App']);

$template = TemplateBuilder::create($scope, 'Professional', 2)
    ->withSeats(10)
    ->withTrialDays(14)      // 14-day trial
    ->withDurationDays(365)  // 1-year subscription
    ->withGracePeriod(14)
    ->enableFeature('api_access')
    ->enableFeature('advanced_reporting')
    ->withEntitlement('api_calls_per_month', 100000)
    ->withEntitlement('storage_gb', 100)
    ->build();
```

## Using Templates

### License Creation from Template

```php
// Basic usage (scoped template)
$license = app(TemplateService::class)->createLicenseForScope($scope, $template->slug, [
    'licensable_type' => App\Models\Organization::class,
    'licensable_id' => $organization->id,
    'key_hash' => License::hashKey($generatedKey),
]);

// With overrides
$license = app(TemplateService::class)->createLicenseForScope($scope, $template->slug, [
    'licensable_type' => App\Models\User::class,
    'licensable_id' => $user->id,
    'key_hash' => License::hashKey($generatedKey),
    'expires_at' => now()->addMonths(6), // Override validity_days
    'max_usages' => 15, // Override template default
    'meta' => [
        'custom_discount' => 20,
        'referral_code' => 'REF123',
    ],
]);
```

### Template-Based License Factory

```php
use Illuminate\Support\Str;

class LicenseFactory
{
    public function createForPlan(
        LicenseScope $scope,
        string|LicenseTemplate $templateRef,
        Model $licensable,
        array $overrides = []
    ): array {
        $template = $templateRef instanceof LicenseTemplate
            ? $templateRef
            : LicenseTemplate::where('license_scope_id', $scope->id)
                ->where('slug', $templateRef)
                ->firstOrFail();

        $key = $this->generateLicenseKey($template);

        $license = app(TemplateService::class)->createLicenseForScope($scope, $template, array_merge([
            'licensable_type' => $licensable::class,
            'licensable_id' => $licensable->getKey(),
            'key_hash' => License::hashKey($key),
        ], $overrides));

        return [
            'license' => $license,
            'key' => $key,
            'template' => $template,
        ];
    }

    private function generateLicenseKey(LicenseTemplate $template): string
    {
        $prefix = Str::of($template->scope?->slug ?? 'global')
            ->upper()
            ->replace('-', '')
            ->substr(0, 3)
            ->padRight(3, 'X');

        $tier = str_pad((string) $template->tier_level, 2, '0', STR_PAD_LEFT);
        $random = Str::upper(Str::random(8));

        return $prefix.'-'.$tier.'-'.$random;
    }
}
```

## Template Management

### Template Queries

```php
// All active templates ordered by tier
$activeTemplates = LicenseTemplate::active()->orderedByTier()->get();

// Templates for a specific scope
$scopeTemplates = LicenseTemplate::getForScope($scope);

// Global catalog templates
$globalTemplates = LicenseTemplate::query()
    ->whereNull('license_scope_id')
    ->active()
    ->orderedByTier()
    ->get();

// Template hierarchy with scope
$template = LicenseTemplate::with(['scope', 'parentTemplate', 'childTemplates'])->find($id);

// Templates with license counts
$templatesWithCounts = LicenseTemplate::with(['scope'])->withCount('licenses')->get();
```

### Template Analytics

```php
class TemplateAnalytics
{
    public function getUsageStats(): array
    {
        return LicenseTemplate::query()
            ->withCount([
                'licenses',
                'licenses as active_licenses_count' => function($query) {
                    $query->where('status', 'active');
                },
                'licenses as expired_licenses_count' => function($query) {
                    $query->where('status', 'expired');
                },
            ])
            ->get()
            ->map(function($template) {
                return [
                    'template' => $template->name,
                    'scope' => $template->scope->slug ?? 'global',
                    'tier_level' => $template->tier_level,
                    'total_licenses' => $template->licenses_count,
                    'active_licenses' => $template->active_licenses_count,
                    'expired_licenses' => $template->expired_licenses_count,
                    'conversion_rate' => $template->licenses_count > 0 
                        ? ($template->active_licenses_count / $template->licenses_count) * 100 
                        : 0,
                ];
            })
            ->sortBy('tier_level');
    }
    
    public function getPopularTemplates(int $limit = 10): Collection
    {
        return LicenseTemplate::query()
            ->with('scope')
            ->withCount('licenses')
            ->orderByDesc('licenses_count')
            ->limit($limit)
            ->get();
    }
}
```

### Template Versioning

```php
class TemplateVersioning
{
    public function createVersion(LicenseTemplate $template, array $changes): LicenseTemplate
    {
        // Create new version with changes
        $newVersion = $template->replicate([
            'slug', // Will be regenerated
            'created_at',
            'updated_at'
        ]);
        
        // Apply changes
        foreach ($changes as $key => $value) {
            $newVersion->$key = $value;
        }
        
        // Increment version in meta
        $version = $template->meta['version'] ?? 1;
        $newVersion->meta = array_merge($template->meta ?? [], [
            'version' => $version + 1,
            'previous_version_id' => $template->id,
            'changelog' => $changes['changelog'] ?? 'No changelog provided',
        ]);
        
        $newVersion->save();
        
        // Deactivate old version
        $template->update(['is_active' => false]);
        
        return $newVersion;
    }
}
```

## Best Practices

### Template Design

1. **Hierarchical Structure**: Use inheritance to avoid duplication
2. **Clear Naming**: Use descriptive names and consistent slug patterns
3. **Tier Logic**: Assign meaningful tier levels for upgrades/downgrades
4. **Feature Granularity**: Define features at appropriate level of detail
5. **Entitlement Clarity**: Use clear, measurable entitlement names

### Template Organization

```php
// Good template structure
$templates = [
    // Free tier
    'free' => ['tier_level' => 0, 'max_usages' => 1],
    
    // Paid tiers with clear progression
    'starter' => ['tier_level' => 1, 'max_usages' => 3],
    'professional' => ['tier_level' => 2, 'max_usages' => 10],
    'business' => ['tier_level' => 3, 'max_usages' => 50],
    'enterprise' => ['tier_level' => 4, 'max_usages' => -1], // Unlimited
    
    // Custom/special tiers
    'custom' => ['tier_level' => 9999, 'max_usages' => null],
];
```

### Configuration Management

```php
// Store complex configurations in templates
$enterpriseConfig = [
    'base_configuration' => [
        'max_usages' => -1, // Unlimited
        'validity_days' => 365,
        'policies' => [
            'grace_days' => 30,
            'over_limit' => 'allow', // Special policy for enterprise
            'priority_support' => true,
        ],
    ],
    'features' => [
        'all_features' => true,
        'custom_integrations' => true,
        'white_labeling' => true,
        'dedicated_support' => true,
    ],
    'entitlements' => [
        'api_calls_per_month' => -1, // Unlimited
        'storage_gb' => -1, // Unlimited
        'users' => -1, // Unlimited
        'custom_fields' => -1, // Unlimited
    ],
];
```

### Migration Strategy

```php
// Handle template changes gracefully
class TemplateMigration
{
    public function migrateExistingLicenses(LicenseTemplate $oldTemplate, LicenseTemplate $newTemplate): void
    {
        $licenses = $oldTemplate->licenses()->active()->get();
        
        foreach ($licenses as $license) {
            // Preserve existing configuration while updating template
            $license->update([
                'template_id' => $newTemplate->id,
                'meta' => array_merge(
                    $license->meta ?? [],
                    ['migrated_from_template' => $oldTemplate->id]
                )
            ]);
        }
        
        // Mark old template as inactive
        $oldTemplate->update(['is_active' => false]);
    }
}
```

The Templates & Tiers system provides powerful configuration management for licensing, enabling you to create flexible, maintainable license structures that can evolve with your business needs.
