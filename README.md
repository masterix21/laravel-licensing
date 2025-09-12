# Laravel Licensing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/masterix21/laravel-licensing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/masterix21/laravel-licensing/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)

Enterprise-grade license management for Laravel applications with offline verification, seat-based licensing, and cryptographic security.

## Installation

Install the package via Composer:

```bash
composer require masterix21/laravel-licensing
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="LucaLongo\Licensing\LicensingServiceProvider"
```

Run the migrations:

```bash
php artisan migrate
```

Generate your root certificate authority key:

```bash
php artisan licensing:keys:make-root
```

Issue your first signing key:

```bash
php artisan licensing:keys:issue-signing --kid signing-key-1
```

## Quick Start

### 1. Create a License

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Facades\Licensing;

// Generate a secure activation key
$activationKey = Str::random(32);

// Create license attached to a user
$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 5, // Allow 5 concurrent devices
    'expires_at' => now()->addYear(),
    'meta' => [
        'product' => 'premium',
        'features' => ['api_access', 'advanced_reports'],
    ],
]);

// License automatically gets a unique UID for API usage
echo $license->uid; // e.g., '01k4wgkvtm4zth0nnz3dawx6yj'

// Activate the license
$license->activate();
```

### 2. Register a Device/Usage

```php
// Register a device with the license
$usage = Licensing::register(
    $license, 
    'device-fingerprint-hash', 
    [
        'device_name' => 'MacBook Pro',
        'app_version' => '1.2.3',
    ]
);

// Update heartbeat to show device is still active
Licensing::heartbeat($usage);
```

### 3. Issue an Offline Token

```php
// Generate a token for offline verification
$token = Licensing::issueToken($license, $usage, [
    'ttl_days' => 7, // Token valid for 7 days offline
]);

// Token can be verified offline on the client
```

### 4. Verify License (Online)

```php
// Find license by activation key (internal use)
$license = License::findByKey($activationKey);

// Find license by UID (for API endpoints)
$license = License::findByUid($uid);

if ($license && $license->isUsable()) {
    // License is valid and active
    $remainingDays = $license->daysUntilExpiration();
    $availableSeats = $license->getAvailableSeats();
}
```

### 5. Verify Token (Offline)

```php
// On the client side, verify token without internet
$publicBundle = file_get_contents('path/to/public-bundle.json');

try {
    $claims = Licensing::verifyOfflineToken($token, $publicBundle);
    // Token is valid, extract license info from claims
    $licenseId = $claims['license_id'];
    $maxUsages = $claims['max_usages'];
} catch (\Exception $e) {
    // Token invalid or expired
}
```

### 6. Trial Management

```php
use LucaLongo\Licensing\Services\TrialService;

$trialService = app(TrialService::class);

// Start a 14-day trial
$trial = $trialService->startTrial($license, 'device-fingerprint', 14);

// Convert trial to full license after purchase
$license = $trialService->convertTrial($trial, 'user_purchase', 99.99);
```

### 7. License Templates & Tiers

```php
use LucaLongo\Licensing\Models\LicenseTemplate;
use LucaLongo\Licensing\Services\TemplateService;

// Create license templates for different tiers
$basic = LicenseTemplate::create([
    'group' => 'saas-app',
    'name' => 'Basic',
    'tier_level' => 1,
    'base_configuration' => [
        'max_usages' => 1,
        'validity_days' => 365,
    ],
    'features' => [
        'basic_features' => true,
        'api_access' => false,
    ],
    'entitlements' => [
        'max_api_calls_per_day' => 100,
        'max_storage_gb' => 1,
    ],
]);

$pro = LicenseTemplate::create([
    'group' => 'saas-app',
    'name' => 'Pro',
    'tier_level' => 2,
    'parent_template_id' => $basic->id, // Inherits from Basic
    'base_configuration' => [
        'max_usages' => 5,
    ],
    'features' => [
        'api_access' => true,
        'export_data' => true,
    ],
    'entitlements' => [
        'max_api_calls_per_day' => 5000,
        'max_storage_gb' => 10,
    ],
]);

// Create license from template
$license = License::createFromTemplate('saas-app-pro', [
    'licensable' => $user,
]);

// Check features and entitlements
if ($license->hasFeature('api_access')) {
    $apiLimit = $license->getEntitlement('max_api_calls_per_day'); // 5000
}

// Upgrade license to higher tier
$templateService = app(TemplateService::class);
$templateService->upgradeLicense($license, 'saas-app-enterprise');
```

### 8. License Transfer & Migration

```php
use LucaLongo\Licensing\Services\LicenseTransferService;
use LucaLongo\Licensing\Enums\TransferType;

// Initiate a secure license transfer
$transferService = app(LicenseTransferService::class);
$transfer = $transferService->initiateTransfer(
    $license,
    $targetUser,
    TransferType::UserToUser,
    $currentUser,
    ['reason' => 'Sale to another user']
);

// Multi-level approval workflow
// Transfer executes automatically when all approvals are received

// For complete transfer documentation, see the docs
```

## Advanced Configuration

### Policies Configuration

```php
// config/licensing.php

'policies' => [
    'over_limit' => 'reject', // or 'auto_replace_oldest'
    'grace_days' => 14, // Days after expiration before hard stop
    'usage_inactivity_auto_revoke_days' => 90, // Auto-cleanup inactive devices
    'unique_usage_scope' => 'license', // or 'global' for system-wide uniqueness
],
```

### Offline Token Configuration

```php
'offline_token' => [
    'enabled' => true,
    'ttl_days' => 7, // How long tokens work offline
    'force_online_after_days' => 14, // Force online check after X days
    'clock_skew_seconds' => 60, // Tolerance for time sync issues
],
```

### Trial Configuration

```php
'trials' => [
    'enabled' => true,
    'default_duration_days' => 14,
    'allow_extensions' => true,
    'max_extension_days' => 7,
    'prevent_reset_attempts' => true,
],
```

### Template Configuration

```php
'templates' => [
    'enabled' => true,
    'allow_inheritance' => true,
    'default_group' => 'default',
],
```

### Security Configuration

```php
'crypto' => [
    'algorithm' => 'ed25519', // Fast and secure
    'keystore' => [
        'driver' => 'files', // or 'database' for key storage
        'path' => storage_path('app/licensing/keys'),
        'passphrase_env' => 'LICENSING_KEY_PASSPHRASE', // Encryption key
    ],
],
```

## CLI Commands

### Key Management

```bash
# Create root certificate authority
php artisan licensing:keys:make-root

# Issue new signing key
php artisan licensing:keys:issue-signing --kid my-key-1

# Rotate keys (revoke current, issue new)
php artisan licensing:keys:rotate --reason routine

# Emergency key revocation
php artisan licensing:keys:revoke signing-key-1 --reason compromised

# List all keys with status
php artisan licensing:keys:list

# Export public keys for clients
php artisan licensing:keys:export --format json --output public-bundle.json
```

### Token Management

```bash
# Issue offline token for testing
php artisan licensing:offline:issue --license ABC123 --fingerprint device-001 --ttl 7d
```

## API Endpoints

When API is enabled, the following endpoints are available:

- `POST /api/licensing/v1/validate` - Validate license online
- `POST /api/licensing/v1/token` - Issue/refresh offline token
- `GET /api/licensing/v1/jwks.json` - Public keys (JWS mode)
- `POST /api/licensing/v1/licenses/{uid}/usages:register` - Register new device
- `POST /api/licensing/v1/licenses/{uid}/usages:heartbeat` - Update device heartbeat
- `POST /api/licensing/v1/licenses/{uid}/usages:revoke` - Revoke device access

**Note:** All license-specific endpoints use the license UID (not the internal ID) to prevent enumeration attacks. The UID is a non-sequential, cryptographically random identifier generated automatically when a license is created.

## Events

The package emits the following events for integration:

- `LicenseActivated` - When a license is first activated
- `LicenseExpiringSoon` - X days before expiration (configurable)
- `LicenseExpired` - When license expires
- `LicenseRenewed` - When license is renewed
- `UsageRegistered` - When new device/usage is registered
- `UsageRevoked` - When device/usage is revoked
- `UsageLimitReached` - When max seats are occupied
- `TrialStarted` - When a trial period begins
- `TrialConverted` - When trial converts to full license
- `TrialExpired` - When trial period expires
- `TrialExtended` - When trial is extended
- `LicenseTransferInitiated` - When a license transfer is requested
- `LicenseTransferCompleted` - When transfer is successfully executed
- `LicenseTransferRejected` - When transfer is rejected by an approver

## Security Best Practices

1. **Never expose activation keys** - Store only salted hashes
2. **Use UIDs for API endpoints** - Never expose internal sequential IDs
3. **Use environment variables** for key passphrases
4. **Rotate signing keys regularly** (monthly recommended)
5. **Monitor audit logs** for suspicious activity
6. **Implement rate limiting** on validation endpoints
7. **Use HTTPS only** for API endpoints
8. **Keep offline token TTL short** (7-14 days)
9. **Enable forced online verification** for critical licenses

## Use Cases

### SaaS Applications
Control feature access and enforce subscription limits with seat-based licensing, template-based tiers (Basic/Pro/Enterprise), and automatic renewals.

### Desktop Software
Provide offline license verification for applications that can't always connect to the internet, with flexible licensing tiers and feature flags.

### API Services
Manage API access with usage-based licensing, concurrent request limits, and entitlement-based quotas.

### Enterprise Software
Deploy on-premise solutions with air-gapped license verification, multi-product template management, and compliance tracking.

### Mobile Applications
Lightweight offline verification with periodic online synchronization and tier-based feature control.

### Multi-Product Portfolios
Centralize license management across different software offerings using template groups and inheritance hierarchies.

## Testing

Run the test suite:

```bash
composer test
```

Run tests with coverage:

```bash
composer test-coverage
```

Static analysis:

```bash
composer analyse
```

## Documentation

For comprehensive documentation including security architecture, API reference, and integration guides, visit [docs/index.html](docs/index.html).

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+
- OpenSSL extension
- Sodium extension (for Ed25519)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker. All security vulnerabilities will be promptly addressed.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Luca Longo](https://github.com/masterix21)
- [All Contributors](../../contributors)

## Support

For commercial support, custom integrations, or enterprise features, please contact l.longo@ambita.it.

---

**Keywords**: Laravel license management, offline license verification, software licensing Laravel, PASETO tokens, Ed25519 signatures, seat-based licensing, SaaS license management, Laravel package, enterprise licensing, device fingerprinting, license key validation, subscription management, API licensing, concurrent usage tracking, cryptographic signatures, license templates, tier-based licensing, feature flags, entitlements, multi-product licensing
