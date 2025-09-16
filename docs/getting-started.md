# 🚀 Getting Started

Welcome to Laravel Licensing! This guide will help you get up and running with enterprise-grade licensing in your Laravel application in just a few minutes.

## Overview

Laravel Licensing provides a complete licensing solution with:
- **License activation and validation**
- **Offline verification with cryptographic tokens**
- **Usage tracking and seat management**
- **Trial licenses with conversion tracking**
- **Template-based license tiers**
- **Comprehensive audit logging**

## Quick Start

### 1. Installation

```bash
composer require lucalongo/laravel-licensing
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

### Method 1: Auto-Generated License Key

```php
use LucaLongo\Licensing\Models\License;

// Create license with auto-generated key
$license = License::createWithKey([
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 3, // Allow 3 devices
    'expires_at' => now()->addYear(),
]);

// Get the generated key immediately
$activationKey = $license->license_key; // e.g., "LIC-A3F2-B9K1-C4D8-E5H7"

// Give the activation key to your customer
echo "Your activation key: {$activationKey}";
```

### Method 2: Custom License Key

```php
// Provide your own license key format
$customKey = 'ENTERPRISE-2024-ANNUAL-001';

$license = License::createWithKey([
    'licensable_type' => Organization::class,
    'licensable_id' => $organization->id,
    'max_usages' => 50, // Enterprise license
    'expires_at' => now()->addYear(),
], $customKey);

echo "Your enterprise key: {$customKey}";
```

### Method 3: Hash-Only (Maximum Security)

```php
// Traditional hash-only approach (no key retrieval)
$activationKey = Str::random(32);

$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 3,
    'expires_at' => now()->addYear(),
]);

// Store key securely (cannot be retrieved later)
echo "Your activation key: {$activationKey}";
```

### Activate a License

```php
// Customer provides their activation key
$providedKey = 'LIC-A3F2-B9K1-C4D8-E5H7';

// Find and activate the license
$license = License::findByKey($providedKey);

if ($license && $license->verifyKey($providedKey)) {
    $license->activate();
    echo "License activated successfully!";
} else {
    echo "Invalid license key!";
}
```

### Register a Device/Usage

```php
use LucaLongo\Licensing\Services\UsageRegistrarService;

$registrar = app(UsageRegistrarService::class);

// Generate device fingerprint (example)
$fingerprint = hash('sha256', $request->ip() . $request->userAgent());

// Register the device
$usage = $registrar->register(
    $license,
    $fingerprint,
    [
        'name' => 'Office Desktop',
        'client_type' => 'desktop',
    ]
);
```

### Check License Features

```php
// Check if license is valid
if ($license->isUsable()) {
    // License is active or in grace period

    // Check remaining days
    $daysLeft = $license->daysUntilExpiration();

    // Check available seats
    $availableSeats = $license->getAvailableSeats();
}
```

### Key Management Operations

```php
// Retrieve the original license key (if enabled in configuration)
if ($license->canRetrieveKey()) {
    $originalKey = $license->retrieveKey();
    echo "Original key: {$originalKey}";
}

// Regenerate license key (useful for security incidents)
if ($license->canRegenerateKey()) {
    $newKey = $license->regenerateKey();
    echo "New key: {$newKey}";
    // Old key no longer works
}

// Verify any provided key
$userProvidedKey = 'LIC-A3F2-B9K1-C4D8-E5H7';
$isValid = $license->verifyKey($userProvidedKey);

// Find license by UID (alternative to key-based lookup)
$license = License::findByUid($uid);
```

## Using Templates

Templates allow you to define reusable license configurations:

### Create a Template

```php
use LucaLongo\Licensing\Models\LicenseTemplate;

$template = LicenseTemplate::create([
    'group' => 'subscriptions',
    'name' => 'Professional Plan',
    'slug' => 'professional-annual',
    'tier_level' => 2,
    'base_configuration' => [
        'max_usages' => 5,
        'validity_days' => 365,
    ],
    'features' => [
        'api_access' => true,
        'advanced_analytics' => true,
        'priority_support' => true,
    ],
    'entitlements' => [
        'api_calls_per_month' => 10000,
        'storage_gb' => 100,
    ],
]);
```

### Create License from Template

```php
$license = License::createFromTemplate('professional-annual', [
    'licensable_type' => Organization::class,
    'licensable_id' => $org->id,
]);

// Check template features
if ($license->hasFeature('advanced_analytics')) {
    // Enable advanced analytics
}

// Get entitlements
$apiCalls = $license->getEntitlement('api_calls_per_month');
```

## Offline Verification

Generate tokens for offline license verification:

```php
use LucaLongo\Licensing\Services\PasetoTokenService;

$tokenService = app(PasetoTokenService::class);

// Issue an offline token
$token = $tokenService->issue($license, $usage, [
    'ttl_days' => 7,
]);

// Token can be verified offline by clients
// using the public key bundle
```

## Trial Licenses

Offer trial licenses with limitations:

```php
use LucaLongo\Licensing\Services\TrialService;

$trialService = app(TrialService::class);

// Start a 30-day trial
$trial = $trialService->start(
    $license,
    $deviceFingerprint,
    30,
    [
        'max_documents' => 10,
        'watermark' => true,
    ]
);

// Later, convert to full license
if ($trial->canConvert()) {
    $fullLicense = $trialService->convert($trial, 'purchase');
}
```

## What's Next?

Now that you have the basics, explore:

- [**Installation Guide**](installation.md) - Detailed installation and setup
- [**Configuration**](configuration.md) - Customize the package
- [**Basic Usage**](basic-usage.md) - Common scenarios and patterns
- [**API Reference**](api/models.md) - Complete API documentation

## Example Application

Check out our [example application](https://github.com/lucalongo/laravel-licensing-example) for a complete implementation including:
- License activation flow
- Admin dashboard
- Customer portal
- API integration
- Offline verification client

## Need Help?

- 📖 Read the [FAQ](reference/faq.md)
- 🔧 Check [Troubleshooting](reference/troubleshooting.md)
- 💬 Join our [Discord Community](https://discord.gg/laravel-licensing)
- 📧 Email support@laravel-licensing.com