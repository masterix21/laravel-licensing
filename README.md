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

### 1. Create and activate a license

```php
use LucaLongo\Licensing\Models\License;

$activationKey = Str::random(32);

$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
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

