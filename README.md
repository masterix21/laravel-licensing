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

> **Passphrase required**: The command encrypts keys using a passphrase stored in the `LICENSING_KEY_PASSPHRASE` environment variable (configurable via `licensing.crypto.keystore.passphrase_env`). If the variable is missing, the command will now prompt you to create one unless you run it with `--silent`/`--no-interaction`. Set it ahead of time (for example `export LICENSING_KEY_PASSPHRASE="your-strong-passphrase"`) to enable non-interactive automation.

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
- **💾 Flexible Key Management**: Auto-generation, custom keys, optional retrieval
- **🔒 Secure Storage**: Encrypted key storage with configurable retrieval
- **⚡ High Performance**: Optimized for enterprise workloads

## Quick Start

### 1. Create and activate a license

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;

// Method 1: Auto-generate license key
$license = License::createWithKey([
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
]);

// The generated key is available immediately after creation
$licenseKey = $license->license_key; // e.g., "LIC-A3F2-B9K1-C4D8-E5H7"

// Method 2: Provide your own license key
$customKey = 'CUSTOM-KEY-12345';
$license = License::createWithKey([
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
], $customKey);

// Method 3: Traditional approach with hash only
$activationKey = Str::random(32);
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

### 5. Retrieve and manage license keys

```php
// Retrieve the original license key (if stored encrypted)
$originalKey = $license->retrieveKey();

// Check if retrieval is available
if ($license->canRetrieveKey()) {
    $key = $license->retrieveKey();
}

// Regenerate a license key
if ($license->canRegenerateKey()) {
    $newKey = $license->regenerateKey();
    // Old key no longer works, new key is returned
}

// Verify a license key
$isValid = $license->verifyKey($providedKey);

// Find license by key
$license = License::findByKey($licenseKey);
```

## License Key Management

The package provides flexible license key management with three configurable services:

### Configuration

```php
// config/licensing.php

'services' => [
    'key_generator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyGenerator::class,
    'key_retriever' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRetriever::class,
    'key_regenerator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRegenerator::class,
],

'key_management' => [
    'retrieval_enabled' => true,     // Allow retrieving original keys
    'regeneration_enabled' => true,  // Allow regenerating keys
    'key_prefix' => 'LIC',          // Prefix for generated keys
    'key_separator' => '-',         // Separator for key segments
],
```

### Custom Key Services

You can implement your own key management services:

```php
use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;

class CustomKeyGenerator implements LicenseKeyGeneratorContract
{
    public function generate(?License $license = null): string
    {
        // Your custom key generation logic
        return 'CUSTOM-' . strtoupper(bin2hex(random_bytes(8)));
    }
}
```

Then register it in the config:

```php
'services' => [
    'key_generator' => \App\Services\CustomKeyGenerator::class,
],
```

### Security Considerations

- **Hashed Storage**: Keys are always stored as salted SHA-256 hashes
- **Encrypted Retrieval**: Original keys can be stored encrypted (optional)
- **Regeneration History**: Previous key hashes are maintained for audit
- **Configurable**: Disable retrieval/regeneration for maximum security

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

## Related Packages

### Laravel Licensing Client
[![Packagist](https://img.shields.io/packagist/v/masterix21/laravel-licensing-client.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing-client)
[![GitHub](https://img.shields.io/badge/GitHub-Repository-blue?style=flat-square&logo=github)](https://github.com/masterix21/laravel-licensing-client)

Client package for Laravel applications that need to validate licenses against a licensing server.

```bash
composer require masterix21/laravel-licensing-client
```

**[View on GitHub →](https://github.com/masterix21/laravel-licensing-client)**

Features:
- Automatic license validation
- Offline token verification
- Usage registration and heartbeat
- Caching for performance
- Middleware for route protection

### Laravel Licensing Filament Manager
[![Packagist](https://img.shields.io/packagist/v/masterix21/laravel-licensing-filament-manager.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing-filament-manager)
[![GitHub](https://img.shields.io/badge/GitHub-Repository-blue?style=flat-square&logo=github)](https://github.com/masterix21/laravel-licensing-filament-manager)

Complete admin panel for Filament to manage licenses, monitor usage, and handle key rotation.

```bash
composer require masterix21/laravel-licensing-filament-manager
```

**[View on GitHub →](https://github.com/masterix21/laravel-licensing-filament-manager)**

Features:
- License management dashboard
- Usage analytics and monitoring
- Key rotation interface
- Scope management
- Audit trail viewer
- Token generation tools

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

- PHP 8.3+
- Laravel 12.0+
- OpenSSL extension
- Sodium extension (for PASETO tokens and Ed25519 signatures)

## Support This Project

### 💖 Sponsor on GitHub

If you find this package useful and want to support its continued development, please consider sponsoring:

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-red?style=flat-square)](https://github.com/sponsors/masterix21)

Your sponsorship helps:
- 🚀 Maintain and improve the package
- 📚 Keep documentation up-to-date
- 🐛 Fix bugs and add new features
- 💬 Provide community support
- 🔒 Ensure security updates

**[Become a sponsor →](https://github.com/sponsors/masterix21)**

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker. All security vulnerabilities will be promptly addressed.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Luca Longo](https://github.com/masterix21)
- [All Contributors](../../contributors)
