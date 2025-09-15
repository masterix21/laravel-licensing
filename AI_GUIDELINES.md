# AI Assistant Guidelines for Laravel Licensing Package

This document provides comprehensive guidelines for AI assistants to effectively work with the Laravel Licensing package. Each section is tailored to specific AI tools while maintaining consistency in approach.

## Table of Contents
- [Overview](#overview)
- [Claude Code Guidelines](#claude-code-guidelines)
- [ChatGPT/Codex Guidelines](#chatgpt-codex-guidelines)
- [GitHub Copilot Guidelines](#github-copilot-guidelines)
- [Junie Guidelines](#junie-guidelines)
- [Common Patterns](#common-patterns)
- [Quick Reference](#quick-reference)

## Overview

Laravel Licensing is an enterprise-grade licensing system for Laravel applications with:
- **Offline verification** using PASETO v4 tokens
- **Two-level key hierarchy** (Root CA → Signing Keys)
- **Seat-based licensing** with usage fingerprints
- **License Scopes** for multi-product/software key isolation
- **Trial management** with conversion tracking
- **Template-based licensing** with inheritance
- **Comprehensive audit logging** with tamper detection

### Package Namespace
```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Facades\Licensing;
use LucaLongo\Licensing\Services\*;
```

### Key Security Principles
1. **Never store plain activation keys** - always hash with `License::hashKey()`
2. **Use constant-time comparison** for key verification
3. **Private keys are encrypted** with environmental passphrase
4. **Tokens are signed, not encrypted** - clients only need public keys

---

## Claude Code Guidelines

### Context Setup for Claude Code

When working with Laravel Licensing in Claude Code, establish context with:

```markdown
I'm working with the Laravel Licensing package (lucalongo/laravel-licensing).
Key facts:
- Uses Ed25519 cryptography with PASETO v4 tokens
- Implements offline verification with public key bundles
- Has polymorphic licensable relationships
- Supports seat-based licensing via LicenseUsage
- Uses LicenseScope for multi-product key isolation
```

### Claude Code Specific Patterns

#### 1. License Creation with Security Focus
```php
// Claude Code emphasizes security - always mention hashing
use LucaLongo\Licensing\Models\License;
use Illuminate\Support\Str;

// Generate secure activation key
$activationKey = Str::random(32); // Store this securely for the customer

// Create license with hashed key
$license = License::create([
    'key_hash' => License::hashKey($activationKey), // Never store plain key
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
    'meta' => [
        'tier' => 'professional',
        'support_level' => 'priority'
    ]
]);

// Activate the license
$license->activate();
```

#### 2. Usage Registration with Concurrency Safety
```php
use LucaLongo\Licensing\Services\UsageRegistrarService;

// Claude Code should handle concurrency
$registrar = app(UsageRegistrarService::class);

try {
    // Register with pessimistic locking
    $usage = $registrar->register(
        $license,
        $deviceFingerprint,
        [
            'name' => 'Office Desktop',
            'client_type' => 'desktop',
            'ip' => request()->ip()
        ]
    );
} catch (MaxUsagesExceededException $e) {
    // Handle over-limit scenario
    if (config('licensing.policies.over_limit') === 'auto_replace_oldest') {
        $usage = $registrar->replaceOldest($license, $deviceFingerprint);
    }
}
```

#### 3. Offline Token Generation
```php
use LucaLongo\Licensing\Services\PasetoTokenService;

$tokenService = app(PasetoTokenService::class);

// Issue offline token with TTL
$token = $tokenService->issue($license, $usage, [
    'ttl_days' => 7,
    'force_online_after_days' => 14,
    'include_entitlements' => true
]);

// Token contains no secrets - safe for client storage
```

#### 4. License Scopes for Multi-Product Support
```php
use LucaLongo\Licensing\Models\LicenseScope;

// Create scope for different products
$erpScope = LicenseScope::create([
    'name' => 'ERP System',
    'slug' => 'erp-system',
    'identifier' => 'com.company.erp',
    'key_rotation_days' => 90,
    'default_max_usages' => 10,
]);

// Create license with scope
$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'license_scope_id' => $erpScope->id,  // Assigns to specific product
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    // Inherits defaults from scope
]);

// Signing keys are automatically selected based on scope
$token = $tokenService->issue($license, $usage); // Uses ERP scope key
```

#### 5. Key Management CLI Commands
```bash
# Claude Code should suggest CLI commands for key management
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing --kid signing-2024-q1
php artisan licensing:keys:issue-signing --scope erp-system  # Scoped key
php artisan licensing:keys:rotate --reason routine
php artisan licensing:keys:export --format json --include-chain
```

### Claude Code Best Practices
1. **Always use dependency injection** for services
2. **Wrap database operations in transactions** for license operations
3. **Use events** for extending functionality
4. **Implement audit logging** for compliance
5. **Test concurrent scenarios** with database locks

---

## ChatGPT/Codex Guidelines

### Context Prompt for ChatGPT

```
I'm using Laravel Licensing (composer: lucalongo/laravel-licensing).
Important: This package uses:
- PASETO v4 for offline tokens (not JWT)
- Ed25519 signatures (not RSA)
- Usage fingerprints for device tracking
- Polymorphic relationships for flexible licensing
```

### ChatGPT Specific Patterns

#### 1. Complete License Lifecycle
```php
// ChatGPT prefers complete examples
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTemplate;

// Method 1: Create from template
$template = LicenseTemplate::where('slug', 'professional-annual')->first();
$license = License::createFromTemplate($template, [
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
]);

// Method 2: Direct creation
$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 10,
    'expires_at' => now()->addYear(),
    'meta' => ['features' => ['api_access', 'advanced_reports']]
]);

// Activation flow
if ($license->canActivate()) {
    $license->activate();

    // Register first usage
    $usage = $license->usages()->create([
        'usage_fingerprint' => hash('sha256', $hardwareId),
        'name' => 'Primary Server',
        'registered_at' => now()
    ]);
}

// Check status
$status = $license->status; // LicenseStatus enum
$isValid = $license->isUsable();
$daysLeft = $license->daysUntilExpiration();
```

#### 2. Trial Management
```php
use LucaLongo\Licensing\Services\TrialService;

$trialService = app(TrialService::class);

// Create trial
$trial = $trialService->createTrial($user, [
    'duration_days' => 14,
    'max_usages' => 1,
    'features' => ['basic_features'],
    'limitations' => ['watermark' => true]
]);

// Check trial status
if ($trial->isInTrial()) {
    $daysRemaining = $trial->trialDaysRemaining();

    // Convert to paid
    if ($paymentSuccessful) {
        $paidLicense = $trialService->convertToPaid($trial, [
            'template' => 'professional-annual',
            'payment_reference' => $paymentId
        ]);
    }
}
```

#### 3. License Scopes for Product Isolation
```php
// ChatGPT - Multi-product licensing setup
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicensingKey;

// Create scopes for different products
$crmScope = LicenseScope::create([
    'name' => 'CRM Platform',
    'slug' => 'crm-platform',
    'identifier' => 'com.company.crm',
    'description' => 'Customer Relationship Management System',
    'key_rotation_days' => 60,
    'default_max_usages' => 5,
    'default_trial_days' => 30,
    'meta' => [
        'product_version' => '2.0',
        'support_email' => 'crm-support@company.com'
    ]
]);

$analyticsScope = LicenseScope::create([
    'name' => 'Analytics Dashboard',
    'slug' => 'analytics-dashboard',
    'identifier' => 'com.company.analytics',
    'key_rotation_days' => 90,
    'default_max_usages' => 20,
]);

// Issue scoped signing keys
Artisan::call('licensing:keys:issue-signing', [
    '--scope' => 'crm-platform',
    '--kid' => 'crm-key-2024'
]);

Artisan::call('licensing:keys:issue-signing', [
    '--scope' => 'analytics-dashboard',
    '--kid' => 'analytics-key-2024'
]);

// Create licenses with automatic scope-based key selection
$crmLicense = License::create([
    'key_hash' => License::hashKey($crmKey),
    'license_scope_id' => $crmScope->id,  // CRM scope
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    'max_usages' => $crmScope->default_max_usages,  // Inherits from scope
]);

// Token will be signed with CRM-specific key
$tokenService = app(PasetoTokenService::class);
$crmToken = $tokenService->issue($crmLicense, $usage);
```

#### 4. Error Handling Patterns
```php
// ChatGPT appreciates comprehensive error handling
use LucaLongo\Licensing\Exceptions\{
    LicenseExpiredException,
    MaxUsagesExceededException,
    InvalidActivationKeyException
};

try {
    // Verify and activate
    $license = License::findByKey($providedKey);

    if (!$license) {
        throw new InvalidActivationKeyException('License not found');
    }

    if (!$license->verifyKey($providedKey)) {
        throw new InvalidActivationKeyException('Invalid key');
    }

    $license->activate();

} catch (LicenseExpiredException $e) {
    return response()->json([
        'error' => 'License has expired',
        'expired_at' => $e->license->expires_at
    ], 403);

} catch (MaxUsagesExceededException $e) {
    return response()->json([
        'error' => 'Maximum devices reached',
        'limit' => $e->license->max_usages
    ], 403);
}
```

### ChatGPT Best Practices
1. **Provide complete, runnable examples**
2. **Include error handling and edge cases**
3. **Show alternative approaches**
4. **Explain the "why" behind security decisions**
5. **Include database migration examples when relevant**

---

## GitHub Copilot Guidelines

### Copilot Comment Triggers

```php
// Create a license for a user with 5 device limit
// → Will suggest: License::create with proper hashing

// Register a new device for this license
// → Will suggest: UsageRegistrarService usage

// Check if license is valid and not expired
// → Will suggest: $license->isUsable() method

// Issue an offline verification token
// → Will suggest: PasetoTokenService::issue()
```

### Copilot Autocomplete Patterns

#### 1. Model Relationships
```php
class User extends Model
{
    // Type: "licenses" → Copilot suggests:
    public function licenses()
    {
        return $this->morphMany(License::class, 'licensable');
    }

    // Type: "activeLicense" → Copilot suggests:
    public function activeLicense()
    {
        return $this->morphOne(License::class, 'licensable')
            ->where('status', LicenseStatus::Active)
            ->where('expires_at', '>', now());
    }
}
```

#### 2. Service Integration
```php
class LicenseController extends Controller
{
    // Type: "public function __construct" → Copilot suggests:
    public function __construct(
        private UsageRegistrarService $registrar,
        private PasetoTokenService $tokenService,
        private TrialService $trialService
    ) {}

    // Type: "public function activate" → Copilot suggests:
    public function activate(Request $request)
    {
        $validated = $request->validate([
            'activation_key' => 'required|string'
        ]);

        $license = License::findByKey($validated['activation_key']);

        if (!$license || !$license->verifyKey($validated['activation_key'])) {
            return response()->json(['error' => 'Invalid activation key'], 400);
        }

        $license->activate();

        return response()->json([
            'license_id' => $license->id,
            'expires_at' => $license->expires_at,
            'max_usages' => $license->max_usages
        ]);
    }
}
```

#### 3. License Scopes with Copilot
```php
// Type: "create scope for mobile app" → Copilot suggests:
$mobileScope = LicenseScope::create([
    'name' => 'Mobile Application',
    'slug' => 'mobile-app',
    'identifier' => 'com.company.mobile',
    'description' => 'iOS and Android mobile application',
    'key_rotation_days' => 30,  // More frequent rotation for mobile
    'default_max_usages' => 3,   // Typical mobile device limit
    'meta' => [
        'platforms' => ['ios', 'android'],
        'min_version' => '2.0.0'
    ]
]);

// Type: "generate signing key for scope" → Copilot suggests:
$signingKey = LicensingKey::generateSigningKey(
    kid: 'mobile-key-' . now()->format('Y-m'),
    scope: $mobileScope
);
$signingKey->save();

// Type: "check if scope needs rotation" → Copilot suggests:
if ($mobileScope->needsKeyRotation()) {
    $mobileScope->rotateKeys('scheduled');
}
```

#### 4. Testing Patterns
```php
class LicenseTest extends TestCase
{
    // Type: "test scoped license" → Copilot suggests:
    public function test_scoped_license_uses_correct_signing_key()
    {
        // Create root key
        $rootKey = LicensingKey::generateRootKey('test-root');
        $rootKey->save();

        // Create scope
        $scope = LicenseScope::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'identifier' => 'com.test.product'
        ]);

        // Create scoped signing key
        $scopedKey = LicensingKey::generateSigningKey('test-key', $scope);
        $scopedKey->save();

        // Create license with scope
        $license = License::create([
            'key_hash' => License::hashKey('test-activation-key'),
            'license_scope_id' => $scope->id,
            'licensable_type' => User::class,
            'licensable_id' => User::factory()->create()->id,
            'max_usages' => 5,
            'expires_at' => now()->addYear()
        ]);

        // Verify scope relationship
        $this->assertEquals($scope->id, $license->license_scope_id);
        $this->assertEquals('test-product', $license->scope->slug);

        // Verify correct signing key is found
        $foundKey = LicensingKey::findActiveSigning($scope);
        $this->assertEquals('test-key', $foundKey->kid);
    }
}
```

### Copilot Best Practices
1. **Use descriptive variable names** for better suggestions
2. **Start with comment describing intent**
3. **Import namespaces first** for accurate autocomplete
4. **Use type hints** for better context
5. **Follow Laravel conventions** for familiar patterns

---

## Junie Guidelines

### Junie Context Configuration

```yaml
# .junie/config.yml
project:
  type: laravel
  packages:
    - lucalongo/laravel-licensing

context:
  licensing:
    namespace: LucaLongo\Licensing
    key_algorithm: Ed25519
    token_format: PASETO v4
    features:
      - offline_verification
      - seat_management
      - trial_licenses
      - audit_logging
```

### Junie Specific Patterns

#### 1. Feature Implementation Requests
```php
// Junie understands high-level requests
// Request: "Add licensing to my SaaS application"

// Junie generates complete implementation:

// 1. Migration
Schema::create('app_licenses', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->string('key_hash')->unique();
    $table->morphs('licensable');
    $table->integer('max_usages')->default(1);
    $table->timestamp('expires_at')->nullable();
    $table->enum('status', ['pending', 'active', 'expired', 'suspended']);
    $table->json('meta')->nullable();
    $table->timestamps();
});

// 2. Model Integration
class Subscription extends Model
{
    public function license()
    {
        return $this->morphOne(License::class, 'licensable');
    }

    public function createLicense(array $features = [])
    {
        $activationKey = Str::random(32);

        $license = License::create([
            'key_hash' => License::hashKey($activationKey),
            'licensable_type' => static::class,
            'licensable_id' => $this->id,
            'max_usages' => $this->plan->device_limit,
            'expires_at' => $this->ends_at,
            'meta' => [
                'plan' => $this->plan->slug,
                'features' => $features
            ]
        ]);

        // Store activation key securely
        $this->update(['activation_key_encrypted' => encrypt($activationKey)]);

        return $license;
    }
}

// 3. API Endpoint
Route::post('/api/licenses/activate', function (Request $request) {
    $validated = $request->validate([
        'key' => 'required|string',
        'device_id' => 'required|string'
    ]);

    $license = License::findByKey($validated['key']);

    if (!$license || !$license->verifyKey($validated['key'])) {
        return response()->json(['error' => 'Invalid license key'], 400);
    }

    // Generate fingerprint
    $fingerprint = hash('sha256', $validated['device_id'] . $request->userAgent());

    try {
        // Register usage
        $usage = app(UsageRegistrarService::class)->register(
            $license,
            $fingerprint,
            ['device_id' => $validated['device_id']]
        );

        // Issue offline token
        $token = app(PasetoTokenService::class)->issue($license, $usage);

        return response()->json([
            'token' => $token,
            'expires_at' => $license->expires_at,
            'features' => $license->meta['features'] ?? []
        ]);

    } catch (MaxUsagesExceededException $e) {
        return response()->json([
            'error' => 'Device limit reached',
            'max_devices' => $license->max_usages
        ], 403);
    }
});
```

#### 2. Multi-Product Architecture with Scopes
```php
// Junie - Enterprise multi-product setup
// Request: "Setup licensing for our product suite"

class ProductSuiteSetup
{
    public function setupLicensingScopes(): array
    {
        $scopes = [];

        // Define product suite
        $products = [
            [
                'name' => 'Enterprise ERP',
                'slug' => 'enterprise-erp',
                'identifier' => 'com.company.erp',
                'key_rotation_days' => 90,
                'default_max_usages' => 100,
                'features' => ['inventory', 'accounting', 'hr']
            ],
            [
                'name' => 'Cloud CRM',
                'slug' => 'cloud-crm',
                'identifier' => 'com.company.crm',
                'key_rotation_days' => 60,
                'default_max_usages' => 50,
                'features' => ['contacts', 'deals', 'automation']
            ],
            [
                'name' => 'Analytics Platform',
                'slug' => 'analytics-platform',
                'identifier' => 'com.company.analytics',
                'key_rotation_days' => 120,
                'default_max_usages' => 200,
                'features' => ['dashboards', 'reports', 'ml']
            ]
        ];

        foreach ($products as $product) {
            // Create scope
            $scope = LicenseScope::create([
                'name' => $product['name'],
                'slug' => $product['slug'],
                'identifier' => $product['identifier'],
                'key_rotation_days' => $product['key_rotation_days'],
                'default_max_usages' => $product['default_max_usages'],
                'meta' => [
                    'features' => $product['features'],
                    'version' => '1.0.0',
                    'api_endpoint' => "https://api.company.com/{$product['slug']}"
                ]
            ]);

            // Generate signing key for this product
            $signingKey = LicensingKey::generateSigningKey(
                kid: "{$product['slug']}-key-" . now()->format('Y-m'),
                scope: $scope
            );
            $signingKey->save();

            // Issue certificate
            $ca = app(CertificateAuthorityService::class);
            $certificate = $ca->issueSigningCertificate(
                $signingKey->getPublicKey(),
                $signingKey->kid,
                now(),
                now()->addDays($product['key_rotation_days']),
                $scope
            );
            $signingKey->update(['certificate' => $certificate]);

            $scopes[$product['slug']] = $scope;
        }

        return $scopes;
    }

    public function createProductLicense(string $productSlug, $customer): License
    {
        $scope = LicenseScope::where('slug', $productSlug)->firstOrFail();

        $activationKey = Str::random(32);

        return License::create([
            'key_hash' => License::hashKey($activationKey),
            'license_scope_id' => $scope->id,
            'licensable_type' => get_class($customer),
            'licensable_id' => $customer->id,
            'max_usages' => $scope->default_max_usages,
            'expires_at' => now()->addYear(),
            'meta' => [
                'product' => $scope->name,
                'features' => $scope->meta['features'] ?? [],
                'activation_key_encrypted' => encrypt($activationKey)
            ]
        ]);
    }
}
```

#### 3. Monitoring & Maintenance
```php
// Junie includes monitoring setup with scope awareness
// Request: "Setup license monitoring dashboard"

class ScopedLicenseMetrics
{
    public function getMetricsByScope(): array
    {
        $metrics = [];

        foreach (LicenseScope::active()->get() as $scope) {
            $metrics[$scope->slug] = [
                'scope_name' => $scope->name,
                'total_licenses' => $scope->licenses()->count(),
                'active_licenses' => $scope->licenses()->active()->count(),
                'expiring_soon' => $scope->licenses()->expiringSoon(days: 30)->count(),
                'usage_rate' => $this->calculateScopeUsageRate($scope),
                'key_rotation_needed' => $scope->needsKeyRotation(),
                'active_signing_key' => $scope->activeSigningKey()?->kid
            ];
        }

        return $metrics;
    }

    private function calculateScopeUsageRate(LicenseScope $scope): float
    {
        $totalSeats = $scope->licenses()->active()->sum('max_usages');
        $usedSeats = LicenseUsage::whereHas('license', function ($q) use ($scope) {
            $q->where('license_scope_id', $scope->id);
        })->active()->count();

        return $totalSeats > 0 ? ($usedSeats / $totalSeats) * 100 : 0;
    }
}

// Scheduled job for scope-aware maintenance
class ScopedMaintenanceJob extends Job
{
    public function handle()
    {
        // Rotate keys per scope schedule
        LicenseScope::active()->each(function ($scope) {
            if ($scope->needsKeyRotation()) {
                $scope->rotateKeys('scheduled');
                Log::info("Rotated keys for scope: {$scope->slug}");
            }
        });

        // Process expirations
        License::expired()->each(function ($license) {
            $license->update(['status' => LicenseStatus::Expired]);
            event(new LicenseExpired($license));
        });

        // Send scope-specific notifications
        LicenseScope::active()->each(function ($scope) {
            $scope->licenses()->expiringSoon(days: 7)->each(function ($license) {
                Mail::to($license->licensable->email)
                    ->send(new ScopedLicenseExpiringMail($license, $scope));
            });
        });
    }
}
```

### Junie Best Practices
1. **Provide high-level requirements** - Junie handles implementation details
2. **Include business context** for better suggestions
3. **Request complete features** rather than individual functions
4. **Specify security requirements** explicitly
5. **Ask for monitoring and maintenance** setup

---

## Common Patterns

### 1. License Scope Management Pattern
```php
// Pattern for managing multi-product licensing
class LicenseScopeManager
{
    public function setupProductScope(array $config): LicenseScope
    {
        // Create or update scope
        $scope = LicenseScope::updateOrCreate(
            ['slug' => $config['slug']],
            [
                'name' => $config['name'],
                'identifier' => $config['identifier'],
                'description' => $config['description'] ?? null,
                'key_rotation_days' => $config['key_rotation_days'] ?? 90,
                'default_max_usages' => $config['default_max_usages'] ?? 5,
                'default_trial_days' => $config['default_trial_days'] ?? 14,
                'meta' => $config['meta'] ?? []
            ]
        );

        // Ensure signing key exists for scope
        if (!$scope->activeSigningKey()) {
            $this->createScopedSigningKey($scope);
        }

        // Check if rotation needed
        if ($scope->needsKeyRotation()) {
            $scope->rotateKeys('scheduled');
        }

        return $scope;
    }

    private function createScopedSigningKey(LicenseScope $scope): LicensingKey
    {
        $key = LicensingKey::generateSigningKey(
            kid: $scope->slug . '-key-' . now()->format('Y-m'),
            scope: $scope
        );
        $key->save();

        // Issue certificate
        $ca = app(CertificateAuthorityService::class);
        $certificate = $ca->issueSigningCertificate(
            $key->getPublicKey(),
            $key->kid,
            now(),
            now()->addDays($scope->key_rotation_days ?? 90),
            $scope
        );
        $key->update(['certificate' => $certificate]);

        return $key;
    }

    public function findScopeForProduct(string $identifier): ?LicenseScope
    {
        return LicenseScope::where('identifier', $identifier)
            ->orWhere('slug', $identifier)
            ->first();
    }
}
```

### 2. License Validation Pattern
```php
// Universal validation pattern for all AI tools
public function validateLicense(string $key, string $fingerprint): array
{
    $license = License::findByKey($key);

    if (!$license || !$license->verifyKey($key)) {
        return ['valid' => false, 'error' => 'Invalid key'];
    }

    if (!$license->isUsable()) {
        return ['valid' => false, 'error' => 'License not usable', 'status' => $license->status];
    }

    $usage = $license->usages()
        ->where('usage_fingerprint', hash('sha256', $fingerprint))
        ->first();

    if (!$usage) {
        try {
            $usage = app(UsageRegistrarService::class)->register($license, $fingerprint);
        } catch (MaxUsagesExceededException $e) {
            return ['valid' => false, 'error' => 'Device limit exceeded'];
        }
    }

    return [
        'valid' => true,
        'license' => $license,
        'usage' => $usage,
        'expires_in_days' => $license->daysUntilExpiration()
    ];
}
```

### 2. Token Verification Pattern
```php
// Offline token verification (client-side compatible)
public function verifyOfflineToken(string $token, string $publicKeyBundle): bool
{
    try {
        $verifier = new PasetoTokenVerifier($publicKeyBundle);
        $claims = $verifier->verify($token);

        // Check token validity
        if ($claims['exp'] < time()) {
            return false;
        }

        // Check force online window
        if (isset($claims['force_online_after']) && $claims['force_online_after'] < time()) {
            // Require online validation
            return $this->validateOnline($claims['license_id']);
        }

        return true;

    } catch (\Exception $e) {
        return false;
    }
}
```

### 3. Renewal Pattern
```php
// License renewal with audit trail
public function renewLicense(License $license, int $months = 12): LicenseRenewal
{
    return DB::transaction(function () use ($license, $months) {
        $renewal = LicenseRenewal::create([
            'license_id' => $license->id,
            'period_start' => $license->expires_at ?? now(),
            'period_end' => ($license->expires_at ?? now())->addMonths($months),
            'amount_cents' => $this->calculateRenewalPrice($license, $months),
            'currency' => 'USD'
        ]);

        $license->update([
            'expires_at' => $renewal->period_end,
            'status' => LicenseStatus::Active
        ]);

        event(new LicenseRenewed($license, $renewal));

        return $renewal;
    });
}
```

---

## Quick Reference

### Essential Commands
```bash
# Key Management
php artisan licensing:keys:make-root                      # Create root CA
php artisan licensing:keys:issue-signing                  # Issue global signing key
php artisan licensing:keys:issue-signing --scope <slug>   # Issue scoped signing key
php artisan licensing:keys:rotate                         # Rotate keys
php artisan licensing:keys:export --format json           # Export public keys

# Token Operations
php artisan licensing:offline:issue --license <id> --fingerprint <fp>

# Maintenance
php artisan licensing:cleanup:expired             # Clean expired licenses
php artisan licensing:notify:expiring             # Send expiration notices
```

### Key Classes & Namespaces
```php
// Models
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;       // Product/software scopes
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicenseRenewal;
use LucaLongo\Licensing\Models\LicenseTemplate;
use LucaLongo\Licensing\Models\LicensingKey;

// Services
use LucaLongo\Licensing\Services\UsageRegistrarService;
use LucaLongo\Licensing\Services\PasetoTokenService;
use LucaLongo\Licensing\Services\TrialService;
use LucaLongo\Licensing\Services\LicenseTransferService;

// Enums
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Enums\KeyStatus;

// Exceptions
use LucaLongo\Licensing\Exceptions\MaxUsagesExceededException;
use LucaLongo\Licensing\Exceptions\LicenseExpiredException;
use LucaLongo\Licensing\Exceptions\InvalidActivationKeyException;

// Events
use LucaLongo\Licensing\Events\LicenseActivated;
use LucaLongo\Licensing\Events\LicenseExpired;
use LucaLongo\Licensing\Events\UsageRegistered;
```

### Configuration Keys
```php
// config/licensing.php
'models' => [
    'license' => License::class,
    'license_usage' => LicenseUsage::class,
],
'policies' => [
    'over_limit' => 'reject', // or 'auto_replace_oldest'
    'grace_days' => 14,
    'unique_usage_scope' => 'license', // or 'global'
],
'offline_token' => [
    'enabled' => true,
    'format' => 'paseto', // or 'jws'
    'ttl_days' => 7,
],
'crypto' => [
    'algorithm' => 'ed25519',
    'keystore' => [
        'driver' => 'files',
        'path' => storage_path('app/licensing/keys'),
    ],
],
```

### Security Checklist
- ✅ Always hash activation keys with `License::hashKey()`
- ✅ Use `hash_equals()` for timing-safe comparisons
- ✅ Store private keys encrypted with passphrase
- ✅ Implement rate limiting on validation endpoints
- ✅ Use database transactions for critical operations
- ✅ Enable audit logging for compliance
- ✅ Rotate signing keys regularly (30-90 days)
- ✅ Use non-PII device fingerprints
- ✅ Implement grace periods for better UX
- ✅ Test concurrent usage registration scenarios

---

## Support & Resources

- **Documentation**: `/docs/README.md`
- **API Reference**: `/docs/api/`
- **Security Guide**: `/docs/advanced/security.md`
- **Examples**: `/docs/examples/`
- **Tests**: `/tests/` (180+ test cases)

For AI-specific questions, refer to the appropriate section above or consult the comprehensive documentation in the `/docs` directory.