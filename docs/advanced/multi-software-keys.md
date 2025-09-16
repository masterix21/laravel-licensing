# Multi-Software Signing Keys with License Scopes

Laravel Licensing uses **License Scopes** to manage multi-product licensing with isolated signing keys. Each scope represents a different software product or application, providing better security isolation and key management for multi-product environments.

## Overview

Instead of using a single global signing key for all licenses, you can create dedicated signing keys for each software product:

```
Root CA Key (Trust Anchor)
    ├── Software A Signing Keys
    │   ├── signing-app-a-2024-01 (active)
    │   └── signing-app-a-2024-02 (pending)
    │
    ├── Software B Signing Keys
    │   ├── signing-app-b-2024-01 (active)
    │   └── signing-app-b-2024-02 (pending)
    │
    └── Global Signing Keys (fallback)
        └── signing-global-2024-01 (active)
```

## Benefits

### 1. **Security Isolation**
Each software product has its own signing keys, limiting the impact of a potential key compromise.

### 2. **Independent Key Rotation**
Rotate keys for one product without affecting others.

### 3. **Compliance & Auditing**
Track key usage per product for better compliance reporting.

### 4. **Multi-Tenant Support**
Perfect for SaaS platforms managing licenses for multiple clients or products.

## Creating License Scopes

### Via Code

```php
use LucaLongo\Licensing\Models\LicenseScope;

// Create scope for ERP system
$erpScope = LicenseScope::create([
    'name' => 'Enterprise ERP',
    'slug' => 'erp-system',
    'identifier' => 'com.company.erp',
    'description' => 'Enterprise Resource Planning System',
    'key_rotation_days' => 90,
    'default_max_usages' => 10,
    'default_duration_days' => 365,
]);

// Create scope for CRM platform
$crmScope = LicenseScope::create([
    'name' => 'CRM Platform',
    'slug' => 'crm-platform',
    'identifier' => 'com.company.crm',
    'description' => 'Customer Relationship Management',
    'key_rotation_days' => 90,
    'default_max_usages' => 5,
    'default_duration_days' => 365,
]);

// Global scope is automatically created when needed
$globalScope = LicenseScope::global();
```

## Creating Scoped Signing Keys

### Via CLI

```bash
# Issue signing key for a specific scope
php artisan licensing:keys:issue-signing --kid="erp-signing-2024-q1"

# The key will be associated with the scope when used

### Via Code

```php
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Models\LicenseScope;

// Get or create scope
$mobileScope = LicenseScope::firstOrCreate(
    ['slug' => 'mobile-app'],
    [
        'name' => 'Mobile Application',
        'identifier' => 'com.company.mobile',
        'key_rotation_days' => 90,
        'default_max_usages' => 1,
    ]
);

// Create signing key for the scope
$signingKey = LicensingKey::generateSigningKey(
    kid: 'mobile-app-key-2024',
    scope: $mobileScope  // Pass the LicenseScope model
);
$signingKey->save();
```

## Assigning Scopes to Licenses

### Method 1: Direct Assignment

Associate a license with a scope:

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;

$erpScope = LicenseScope::findBySlugOrIdentifier('erp-system');

$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    'license_scope_id' => $erpScope->id,  // Associate with scope
    'max_usages' => 10,
    'expires_at' => now()->addYear(),
]);

// License will inherit defaults from scope if not specified
$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    'license_scope_id' => $erpScope->id,
    // max_usages and expires_at will use scope defaults
]);
```

### Method 2: Using Scope Defaults

Leverage scope's default settings:

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;

$scope = LicenseScope::findBySlugOrIdentifier('erp-system');

// Get default attributes from scope
$defaults = $scope->getDefaultLicenseAttributes();
// Returns: [
//     'max_usages' => 10,
//     'expires_at' => Carbon instance,
//     'meta' => ['scope' => 'erp-system', ...]
// ]

// Create license with scope defaults
$license = License::create(array_merge($defaults, [
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    'license_scope_id' => $scope->id,
]));
```

### Method 3: Dynamic Assignment

Assign scope based on business logic:

```php
use LucaLongo\Licensing\Models\LicenseScope;

class LicenseService
{
    public function createLicense(Product $product, User $user): License
    {
        // Determine scope based on product
        $scope = match($product->category) {
            'enterprise' => LicenseScope::findBySlugOrIdentifier('erp-system'),
            'smb' => LicenseScope::findBySlugOrIdentifier('crm-platform'),
            'startup' => LicenseScope::findBySlugOrIdentifier('analytics-tool'),
            default => LicenseScope::global()  // Use global scope
        };

        return License::create([
            'key_hash' => License::hashKey($this->generateKey()),
            'licensable_type' => User::class,
            'licensable_id' => $user->id,
            'license_scope_id' => $scope->id,
            'max_usages' => $product->device_limit,
            'expires_at' => now()->addDays($product->duration_days),
        ]);
    }
}
```

## Token Generation with Scoped Keys

When issuing offline tokens, the system automatically uses the appropriate signing key:

```php
use LucaLongo\Licensing\Services\PasetoTokenService;

$tokenService = app(PasetoTokenService::class);

// The service automatically:
// 1. Checks license->signing_scope
// 2. Finds active signing key for that scope
// 3. Falls back to global key if scoped key not found
$token = $tokenService->issue($license, $usage);
```

### Fallback Behavior

The token service follows this priority:

1. **Scoped Key**: Use the signing key for `license->scope`
2. **Global Key**: If no scoped key exists or license has no scope, use the global signing key
3. **Error**: If no keys available, throw exception

## Managing Multiple Scopes

### List Keys by Scope

```php
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicensingKey;

// Get scope with its signing keys
$scope = LicenseScope::findBySlugOrIdentifier('erp-system');
$activeKey = $scope->activeSigningKey();
$allKeys = $scope->signingKeys()->get();

// Get all active scopes
$activeScopes = LicenseScope::active()->get();

// Get scopes needing key rotation
$scopesNeedingRotation = LicenseScope::needingRotation()->get();
```

### Rotate Keys for Specific Scope

```php
use LucaLongo\Licensing\Models\LicenseScope;

class ScopedKeyRotationService
{
    public function rotateScope(string $scopeSlug): void
    {
        $scope = LicenseScope::findBySlugOrIdentifier($scopeSlug);

        if ($scope->needsKeyRotation()) {
            // This handles everything: revokes old keys, creates new one, updates timestamps
            $newKey = $scope->rotateKeys('Scheduled rotation');

            // Log the rotation
            Log::info('Rotated keys for scope', [
                'scope' => $scope->slug,
                'new_kid' => $newKey->kid,
                'next_rotation' => $scope->next_key_rotation_at,
            ]);
        }
    }

    public function rotateAllScopes(): void
    {
        LicenseScope::needingRotation()->each(function ($scope) {
            $scope->rotateKeys('Scheduled rotation');
        });
    }
}
```

## Security Considerations

### 1. **Scope Naming Convention**

Use consistent, meaningful scope names:

```php
// Good
'erp-system'
'mobile-app-ios'
'web-platform-eu'

// Avoid
'app1'
'test'
'new'
```

### 2. **Scope Identifiers**

Use reverse domain notation for uniqueness:

```php
'com.company.product'
'org.organization.service'
'io.startup.app'
```

### 3. **Key Rotation Schedule**

Different scopes can have different rotation schedules:

```php
// High-security product: rotate monthly
$this->rotateScope('banking-app', days: 30);

// Standard product: rotate quarterly
$this->rotateScope('crm-platform', days: 90);

// Legacy product: rotate annually
$this->rotateScope('legacy-system', days: 365);
```

### 4. **Audit Logging**

Track scope-specific key operations:

```php
$auditLogger->log(
    AuditEventType::KeySigningIssued,
    [
        'kid' => $key->kid,
        'scope' => $key->scope,
        'scope_identifier' => $key->scope_identifier,
        'product' => $this->getProductName($key->scope),
    ]
);
```

## Client-Side Verification

Clients need the appropriate public key bundle for their scope:

```bash
# Export public keys for specific scope
php artisan licensing:keys:export \
  --scope="mobile-app" \
  --format=json \
  --output=mobile-app-bundle.json
```

Client verification remains the same:

```php
// Client-side verification (unchanged)
$verifier = new PasetoTokenVerifier($publicKeyBundle);
$isValid = $verifier->verify($token);
```

## Best Practices

### 1. **Use Scopes for Product Separation**

```php
// Different products
'desktop-app'     // Desktop application
'mobile-app'      // Mobile application
'web-service'     // Web service API

// Different environments
'production'      // Production licenses
'staging'         // Staging/test licenses
'trial'          // Trial licenses
```

### 2. **Implement Scope-Based Policies**

```php
class LicensePolicy
{
    public function getMaxUsages(string $scope): int
    {
        return match($scope) {
            'enterprise' => 1000,
            'professional' => 100,
            'starter' => 10,
            default => 5
        };
    }
}
```

### 3. **Monitor Scope Usage**

```php
// Dashboard metrics per scope
$metrics = [
    'erp-system' => [
        'active_licenses' => License::where('signing_scope', 'erp-system')->active()->count(),
        'total_seats' => LicenseUsage::whereHas('license', fn($q) => $q->where('signing_scope', 'erp-system'))->count(),
    ],
    // ... other scopes
];
```

### 4. **Plan for Migration**

When introducing scoped keys to existing system:

```php
// Migration strategy
class MigrateToScopedKeys
{
    public function migrate()
    {
        // 1. Create scoped keys for each product
        $this->createScopedKeys();

        // 2. Update existing licenses
        License::whereNull('signing_scope')
            ->update(['signing_scope' => $this->inferScope()]);

        // 3. Keep global key as fallback
        $this->ensureGlobalKeyExists();
    }
}
```

## Example: Multi-Product SaaS

```php
class MultiProductLicenseManager
{
    private array $products = [
        'erp' => [
            'scope' => 'erp-system',
            'identifier' => 'com.company.erp',
            'rotation_days' => 90,
        ],
        'crm' => [
            'scope' => 'crm-platform',
            'identifier' => 'com.company.crm',
            'rotation_days' => 90,
        ],
        'analytics' => [
            'scope' => 'analytics-tool',
            'identifier' => 'com.company.analytics',
            'rotation_days' => 180,
        ],
    ];

    public function setupProduct(string $product): void
    {
        $config = $this->products[$product];

        // Create signing key for product
        $key = LicensingKey::generateSigningKey(
            kid: "{$config['scope']}-" . now()->format('Y-m'),
            scope: $config['scope'],
            scopeIdentifier: $config['identifier']
        );
        $key->save();

        // Schedule rotation
        $this->scheduleRotation($config['scope'], $config['rotation_days']);
    }

    public function createLicense(string $product, array $data): License
    {
        $config = $this->products[$product];

        return License::create(array_merge($data, [
            'signing_scope' => $config['scope'],
        ]));
    }
}
```

## Troubleshooting

### Issue: Token generation fails with "No active signing key found for scope"

**Solution**: Ensure a signing key exists for the scope:

```bash
php artisan licensing:keys:issue-signing --scope="your-scope"
```

### Issue: Clients can't verify tokens after adding scopes

**Solution**: Export and distribute new public key bundles:

```bash
php artisan licensing:keys:export --scope="your-scope" --include-chain
```

### Issue: Need to change a license's scope

**Solution**: Update the license and reissue tokens:

```php
$license->update(['signing_scope' => 'new-scope']);
// Existing tokens remain valid until expiration
// New tokens will use the new scope's key
```