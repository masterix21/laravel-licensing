# Laravel Licensing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/masterix21/laravel-licensing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/masterix21/laravel-licensing/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)

Enterprise-grade license management for Laravel applications with offline verification, seat-based licensing, cryptographic security, and multi-product support through License Scopes.

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

## Key Features

- **🔐 Offline Verification**: PASETO v4 tokens with Ed25519 signatures
- **🪑 Seat-Based Licensing**: Control device/user limits per license
- **🔄 License Lifecycles**: Activation, renewal, grace periods, and expiration
- **🏢 Multi-Product Support**: License Scopes for product/software isolation
- **🔑 Two-Level Key Hierarchy**: Root CA → Signing Keys for secure rotation
- **📊 Comprehensive Audit Trail**: Track all license and usage events
- **🎯 Flexible Assignment**: Polymorphic relationships for any model
- **⚡ High Performance**: Optimized for enterprise workloads

## Quick Start

### 1. Create and activate a license

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;

$activationKey = Str::random(32);

// Optional: Create a scope for product isolation
$scope = LicenseScope::create([
    'name' => 'My Product',
    'slug' => 'my-product',
    'identifier' => 'com.company.product',
]);

$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'license_scope_id' => $scope->id ?? null,  // Optional scope
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
]);

$license->activate();
```

### 2. Register a device

```php
use LucaLongo\Licensing\Facades\Licensing;

$usage = Licensing::register(
    $license, 
    'device-fingerprint-hash', 
    ['device_name' => 'MacBook Pro']
);
```

### 3. Issue an offline token

```php
$token = Licensing::issueToken($license, $usage, [
    'ttl_days' => 7,
]);
```

### 4. Verify license

```php
if ($license->isUsable()) {
    $remainingDays = $license->daysUntilExpiration();
    $availableSeats = $license->getAvailableSeats();
}
```

## Multi-Product Licensing with Scopes

License Scopes enable you to manage multiple products/software with isolated signing keys, preventing key compromise in one product from affecting others.

### Create product scopes

```php
use LucaLongo\Licensing\Models\LicenseScope;

// Create scope for your ERP system
$erpScope = LicenseScope::create([
    'name' => 'ERP System',
    'slug' => 'erp-system',
    'identifier' => 'com.company.erp',
    'key_rotation_days' => 90,
    'default_max_usages' => 100,
]);

// Create scope for your mobile app
$mobileScope = LicenseScope::create([
    'name' => 'Mobile App',
    'slug' => 'mobile-app',
    'identifier' => 'com.company.mobile',
    'key_rotation_days' => 30,  // More frequent rotation
    'default_max_usages' => 3,
]);
```

### Issue scoped signing keys

```bash
# Issue signing key for ERP system
php artisan licensing:keys:issue-signing --scope erp-system --kid erp-key-2024

# Issue signing key for mobile app
php artisan licensing:keys:issue-signing --scope mobile-app --kid mobile-key-2024
```

### Create scoped licenses

```php
// Create license for ERP system
$erpLicense = License::create([
    'key_hash' => License::hashKey($erpActivationKey),
    'license_scope_id' => $erpScope->id,  // Scoped to ERP
    'licensable_type' => Company::class,
    'licensable_id' => $company->id,
    'max_usages' => 100,
    'expires_at' => now()->addYear(),
]);

// Create license for mobile app
$mobileLicense = License::create([
    'key_hash' => License::hashKey($mobileActivationKey),
    'license_scope_id' => $mobileScope->id,  // Scoped to mobile
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 3,
    'expires_at' => now()->addMonths(6),
]);

// Tokens are automatically signed with the correct scope-specific key
$erpToken = Licensing::issueToken($erpLicense, $erpUsage);
$mobileToken = Licensing::issueToken($mobileLicense, $mobileUsage);
```

### Benefits of License Scopes

- **Key Isolation**: Each product has its own signing keys
- **Independent Rotation**: Different rotation schedules per product
- **Blast Radius Limitation**: Key compromise affects only one product
- **Product-Specific Defaults**: Configure max usages, trial days per scope
- **Flexible Management**: Programmatic or CLI-based key management


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

For comprehensive documentation visit the [documentation](docs/README.md).

### AI Assistant Support

This package includes comprehensive guidelines for AI coding assistants. See [AI_GUIDELINES.md](AI_GUIDELINES.md) for:
- Claude Code integration patterns
- ChatGPT/Codex usage examples
- GitHub Copilot autocomplete triggers
- Junie configuration and patterns

## Requirements

- PHP 8.2+
- Laravel 12.0+
- OpenSSL extension
- Sodium extension (for PASETO tokens and Ed25519 signatures)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker. All security vulnerabilities will be promptly addressed.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Luca Longo](https://github.com/masterix21)
- [All Contributors](../../contributors)

