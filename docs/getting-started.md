# Getting Started

This guide walks you through the basics of Laravel Licensing — from installation to issuing your first offline token.

## Overview

Laravel Licensing provides:
- License activation and validation
- Offline verification with cryptographic tokens
- Usage tracking and seat management
- Trial licenses with conversion tracking
- Template-based license tiers
- Audit logging

## Quick Start

### 1. Installation

```bash
composer require masterix21/laravel-licensing
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="LucaLongo\Licensing\LicensingServiceProvider"
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Generate Root Key

```bash
php artisan licensing:keys:make-root
```

### 5. Issue Signing Key

```bash
php artisan licensing:keys:issue-signing --days=30
```

## Your First License

### Auto-generated key

```php
use LucaLongo\Licensing\Models\License;

$license = License::createWithKey([
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 3,
    'expires_at' => now()->addYear(),
]);

$activationKey = $license->license_key; // e.g. "LIC-A3F2B9K1-C4D8E5H7-9D2EK8F3-L6A9M1B4"
```

### Custom key

```php
$license = License::createWithKey([
    'licensable_type' => Organization::class,
    'licensable_id' => $organization->id,
    'max_usages' => 50,
    'expires_at' => now()->addYear(),
], 'ENTERPRISE-2024-ANNUAL-001');
```

### Hash-only (no retrieval)

```php
$activationKey = Str::random(32);

$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 3,
    'expires_at' => now()->addYear(),
]);
```

### Activate a license

```php
$license = License::findByKey($providedKey);

if ($license && $license->verifyKey($providedKey)) {
    $license->activate();
}
```

### Register a device

```php
use LucaLongo\Licensing\Services\UsageRegistrarService;

$registrar = app(UsageRegistrarService::class);

$fingerprint = hash('sha256', $request->ip() . $request->userAgent());

$usage = $registrar->register(
    $license,
    $fingerprint,
    [
        'name' => 'Office Desktop',
        'client_type' => 'desktop',
    ]
);
```

### Check license status

```php
if ($license->isUsable()) {
    $daysLeft = $license->daysUntilExpiration();
    $availableSeats = $license->getAvailableSeats();
}
```

### Key management

```php
if ($license->canRetrieveKey()) {
    $originalKey = $license->retrieveKey();
}

if ($license->canRegenerateKey()) {
    $newKey = $license->regenerateKey();
}

$isValid = $license->verifyKey($userProvidedKey);
```

## Using Templates

Templates define reusable license configurations:

```php
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicenseTemplate;

$scope = LicenseScope::firstOrCreate(['slug' => 'saas-app'], ['name' => 'SaaS App']);

$template = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name' => 'Professional Plan',
    'tier_level' => 2,
    'base_configuration' => [
        'max_usages' => 5,
        'validity_days' => 365,
    ],
    'features' => [
        'api_access' => true,
        'advanced_analytics' => true,
    ],
    'entitlements' => [
        'api_calls_per_month' => 10000,
        'storage_gb' => 100,
    ],
]);
```

Create a license from a template:

```php
$license = License::createFromTemplate($template->slug, [
    'licensable_type' => Organization::class,
    'licensable_id' => $org->id,
]);

if ($license->hasFeature('advanced_analytics')) {
    // Enable advanced analytics
}

$apiCalls = $license->getEntitlement('api_calls_per_month');
```

## Offline Verification

Generate tokens for offline license verification:

```php
use LucaLongo\Licensing\Services\PasetoTokenService;

$tokenService = app(PasetoTokenService::class);

$token = $tokenService->issue($license, $usage, [
    'ttl_days' => 7,
]);

// The token can be verified offline using the public key bundle
```

## Trial Licenses

```php
use LucaLongo\Licensing\Services\TrialService;

$trialService = app(TrialService::class);

$trial = $trialService->start(
    $license,
    $deviceFingerprint,
    30,
    [
        'max_documents' => 10,
        'watermark' => true,
    ]
);

// Convert to full license
if ($trial->canConvert()) {
    $fullLicense = $trialService->convert($trial, 'purchase');
}
```

## What's Next?

- [Installation Guide](installation.md) — detailed installation and setup
- [Configuration](configuration.md) — customize the package
- [Basic Usage](basic-usage.md) — common scenarios and patterns
- [API Reference](api/models.md) — complete API documentation
