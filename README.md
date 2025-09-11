# Laravel Licensing - Enterprise-Grade License Management for Laravel Applications

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/masterix21/laravel-licensing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/masterix21/laravel-licensing/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)
[![License](https://img.shields.io/packagist/l/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)

**Laravel Licensing** is a comprehensive license management system for Laravel applications that provides enterprise-grade security, offline verification capabilities, and flexible seat-based licensing. Perfect for SaaS applications, desktop software, APIs, and any Laravel-based product requiring robust license enforcement.

## Key Features

### 🔐 **Enterprise Security Architecture**
- **Two-level cryptographic key hierarchy** with root certificate authority and rotating signing keys
- **Ed25519 digital signatures** for maximum security and performance
- **PASETO v4 tokens** for offline license verification (superior to JWT)
- **Tamper-evident audit logging** with hash-chained records
- **Zero-knowledge key storage** - private keys never exposed in plaintext

### 🪪 **Flexible License Management**
- **Polymorphic license assignment** - attach licenses to any Laravel model (User, Organization, Team, etc.)
- **Secure API identification** using ULIDs instead of sequential IDs to prevent enumeration attacks
- **Seat-based licensing** with configurable usage limits and policies
- **Multiple license states** - pending, active, grace period, expired, suspended, cancelled
- **Automatic state transitions** via scheduled jobs
- **License renewals** with full history tracking

### 🖥️ **Offline-First Verification**
- **No internet required** for license validation after initial activation
- **Cryptographically signed tokens** with configurable TTL (Time To Live)
- **Forced online verification windows** to ensure periodic synchronization
- **Clock skew tolerance** for time synchronization issues
- **Certificate chain validation** for trust verification

### 📊 **Advanced Usage Tracking**
- **Device/VM/Service fingerprinting** without storing PII
- **Concurrent usage enforcement** with pessimistic locking
- **Configurable over-limit policies** - reject or auto-replace oldest
- **Usage heartbeat monitoring** with automatic inactive device cleanup
- **Per-license or global uniqueness scopes**

### 🎯 **Trial Management** (New!)
- **Flexible trial periods** with configurable duration and extensions
- **Trial-to-license conversion** tracking with revenue attribution
- **Feature limitations** during trial period
- **Trial reset prevention** using device fingerprinting
- **Trial analytics** with conversion rate tracking
- **Automatic trial expiration** handling

### 🛠️ **Developer-Friendly**
- **Comprehensive CLI tools** for key management and token generation
- **RESTful API endpoints** with built-in rate limiting
- **Event-driven architecture** for custom integrations
- **Fully configurable** via published config files
- **Extensive test coverage** including security scenarios
- **Laravel best practices** throughout

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
Control feature access and enforce subscription limits with seat-based licensing and automatic renewals.

### Desktop Software
Provide offline license verification for applications that can't always connect to the internet.

### API Services
Manage API access with usage-based licensing and concurrent request limits.

### Enterprise Software
Deploy on-premise solutions with air-gapped license verification and compliance tracking.

### Mobile Applications
Lightweight offline verification with periodic online synchronization.

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

**Keywords**: Laravel license management, offline license verification, software licensing Laravel, PASETO tokens, Ed25519 signatures, seat-based licensing, SaaS license management, Laravel package, enterprise licensing, device fingerprinting, license key validation, subscription management, API licensing, concurrent usage tracking, cryptographic signatures
