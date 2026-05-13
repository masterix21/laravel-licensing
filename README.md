# Laravel Licensing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/masterix21/laravel-licensing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/masterix21/laravel-licensing/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/masterix21/laravel-licensing.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-licensing)

A licensing package for Laravel with offline verification, seat management, cryptographic key rotation, and multi-product support.

## Features

- **Offline verification** — PASETO v4 tokens signed with Ed25519, verifiable without a server connection
- **Seat-based licensing** — control how many devices, users, or instances can use a license
- **Full lifecycle management** — activation, renewal, grace periods, expiration, suspension
- **Multi-product scopes** — isolate signing keys per product so a compromise doesn't spread
- **Two-level key hierarchy** — root CA signs short-lived signing keys; rotate without breaking clients
- **Audit trail** — append-only log of every license, usage, and key event
- **Polymorphic assignment** — attach a license to any Eloquent model
- **Flexible key management** — auto-generation, custom keys, encrypted storage with optional retrieval

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `ext-openssl` and `ext-sodium`

## Installation

```bash
composer require masterix21/laravel-licensing
```

Publish config and migrations, then migrate:

```bash
php artisan vendor:publish --provider="LucaLongo\Licensing\LicensingServiceProvider"
php artisan migrate
```

Generate your root key and first signing key:

```bash
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing --kid signing-key-1
```

> The root key is encrypted with the passphrase from the `LICENSING_KEY_PASSPHRASE` env variable. If missing, the command will prompt you to set one (unless running with `--no-interaction`).

### MySQL / MariaDB notes

Migrations are tested against MySQL 8 and MariaDB 11 in CI. Two points worth knowing if you run into errors on older setups:

- **Identifier 1059 errors** (`Identifier name '…' is too long`): the package already ships explicit short names for the only composite indexes that would exceed MySQL's 64-char limit. If you add custom migrations on top, remember to pass a short alias to `morphs()` / `index()` when the auto-generated name would overflow.
- **Key length 1071 errors** (`Specified key was too long`): only relevant on MySQL < 5.7 or MariaDB < 10.2 with InnoDB's old row format. Add `Schema::defaultStringLength(191);` in your `AppServiceProvider::boot()` as per the [Laravel docs](https://laravel.com/docs/migrations#index-lengths-mysql-mariadb). This is unrelated to identifier length — it caps the indexed VARCHAR prefix, not the index name.

## Quick Start

### Create and activate a license

```php
use LucaLongo\Licensing\Models\License;

$license = License::createWithKey([
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
]);

// The plain-text key is available right after creation
$licenseKey = $license->license_key; // e.g. "LIC-A3F2-B9K1-C4D8-E5H7"

$license->activate();
```

You can also pass your own key as second argument to `createWithKey()`, or use the lower-level `License::create()` with a pre-hashed key via `License::hashKey()`.

### Register a device (seat)

```php
use LucaLongo\Licensing\Facades\Licensing;

$usage = Licensing::register(
    $license,
    'device-fingerprint-hash',
    ['device_name' => 'MacBook Pro']
);
```

### Issue an offline token

```php
$token = Licensing::issueToken($license, $usage, [
    'ttl_days' => 7,
]);
```

### Check license status

```php
if ($license->isUsable()) {
    $remainingDays = $license->daysUntilExpiration();
    $availableSeats = $license->getAvailableSeats();
}
```

### Key retrieval and regeneration

```php
$originalKey = $license->retrieveKey();       // if encrypted storage is enabled
$newKey = $license->regenerateKey();           // old key stops working
$isValid = $license->verifyKey($providedKey);
$license = License::findByKey($licenseKey);
```

## Multi-Product Scopes

Scopes let you manage multiple products with independent signing keys and rotation schedules.

```php
use LucaLongo\Licensing\Models\LicenseScope;

$scope = LicenseScope::create([
    'name' => 'ERP System',
    'slug' => 'erp-system',
    'identifier' => 'com.company.erp',
    'key_rotation_days' => 90,
    'default_max_usages' => 100,
]);
```

Issue a signing key for this scope:

```bash
php artisan licensing:keys:issue-signing --scope erp-system --kid erp-key-2024
```

When you create a license with a `license_scope_id`, tokens are automatically signed with the scope's key. A compromised key in one scope doesn't affect the others.

## Key Management

Key generation, retrieval, and regeneration are handled by pluggable services:

```php
// config/licensing.php
'services' => [
    'key_generator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyGenerator::class,
    'key_retriever' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRetriever::class,
    'key_regenerator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRegenerator::class,
],
```

Implement `LicenseKeyGeneratorContract` (or the retriever/regenerator contracts) to plug in your own logic.

## Related Packages

| Package | Description |
|---------|-------------|
| [laravel-licensing-client](https://github.com/masterix21/laravel-licensing-client) | Client package for validating licenses against a server — offline verification, usage registration, route middleware |
| [laravel-licensing-filament-manager](https://github.com/masterix21/laravel-licensing-filament-manager) | Filament admin panel for license management, usage monitoring, key rotation, and audit trail |

## Testing

```bash
composer test            # run tests
composer test-coverage   # with coverage
composer analyse         # static analysis
```

## Laravel Boost integration

This package ships AI guidelines under `resources/boost/guidelines/laravel-licensing/core.blade.php`. Apps using [Laravel Boost](https://github.com/laravel/boost) auto-discover them:

```bash
php artisan boost:install            # first time, or
php artisan boost:update --discover  # to pick up after adding the package
```

The guidelines cover: core concepts, licenses, usages/seats, scopes & templates, trials, offline tokens, CLI, and API/security. AI assistants (Claude Code, Copilot, Cursor, …) will follow them when generating code against `laravel-licensing`.

> **Heads up:** `boost:update --discover` uses an interactive multi-select. On a TTY, select `masterix21/laravel-licensing` when prompted. In non-interactive environments (CI, automation), the prompt is silently skipped and the package is **not** added — append it manually to `boost.json`:
>
> ```json
> {
>     "packages": ["masterix21/laravel-licensing"]
> }
> ```
>
> then re-run `php artisan boost:update --no-interaction`.

To verify the integration end-to-end against a throwaway Laravel app, run `scripts/test-boost-e2e.sh` from the package root.

## Documentation

Full documentation is available in the [docs](docs/README.md) folder.

## Sponsor

If this package is useful to you, consider [sponsoring its development](https://github.com/sponsors/masterix21).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover a security vulnerability, please email security@example.com instead of using the issue tracker.

## License

MIT. See [LICENSE.md](LICENSE.md).

## Credits

- [Luca Longo](https://github.com/masterix21)
- [All Contributors](../../contributors)
