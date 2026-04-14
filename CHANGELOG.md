# Changelog

All notable changes to `laravel-licensing` will be documented in this file.

## 2.0.1 - 2026-04-14

### Bug Fixes

- **Paseto signing reliability**: regenerate signing keys whose raw Ed25519 bytes contain the `\r\n` sequence. Paseto v4's `AsymmetricSecretKey::raw()` pipes the key through `str_replace("\r\n", "\n", …)`, silently dropping a byte on ~0.1% of randomly generated keys and making `sodium_crypto_sign_detached()` fail with "Signing failed". `HasKeyStore::generate()` now rejects such keys before they reach storage.
- **Paseto pinning**: pinned `paragonie/paseto` to `^3.5` and fed the signer a seed-derived secret key so the v4 misuse-resistance check succeeds regardless of how the key was originally stored.
- **Transfer history integrity hash**: stabilised the hash computation on MySQL so the value no longer depends on column ordering or collation.
- **MySQL migrations**: fixed failures caused by long index names and foreign key ordering.
- **`extension_reason` column**: stored as `TEXT` to accommodate longer justifications.

### Improvements

- **Test schema lifecycle**: let Laravel manage the test schema through the `afterDatabaseRefreshed` hook, with migration order derived from the service provider to keep CI runs deterministic.
- **Docs**: aligned `AI_GUIDELINES.md` and `CLAUDE.md` with the current codebase.

## 2.0.0 - 2026-04-08

### What's Changed

See [UPGRADE.md](UPGRADE.md) for migration instructions.

#### Breaking Changes

- **License detail endpoint** changed from `GET /licenses/{licenseKey}` to `POST /licenses/show` requiring `license_key` and `fingerprint` in the request body
- **Heartbeat metadata** now stored under `client_data` key in usage meta instead of merging at root level
- **Health endpoint** no longer exposes `kid`, `valid_until`, or database error messages

#### Security Improvements

- **License key entropy**: Increased from ~95-bit to 128-bit using `random_bytes()` instead of `Str::random()`
- **KID generation**: Replaced predictable `uniqid()` with `bin2hex(random_bytes(16))` for signing key identifiers
- **API error sanitization**: Internal exception messages no longer leaked to clients; errors are logged server-side via `report()`
- **Rate limiting**: Applied `throttle` middleware to all API endpoints using configurable limits from `config/licensing.php`
- **Fingerprint validation**: Added `max:255` length constraint to all API fingerprint inputs
- **Trial fingerprint hashing**: Upgraded from plain SHA256 to HMAC-SHA256 with automatic legacy fallback for existing trials
- **Heartbeat meta injection**: Client data namespaced under `client_data` key to prevent overwriting internal metadata

#### Bug Fixes

- Fixed Ed25519 key generation on PHP 8.5 (sodium_memzero buffer corruption)
- Fixed `LicenseTemplate::licenses()` relationship missing explicit foreign key
- Fixed PHPStan configuration for cross-version compatibility

#### Improvements

- Made `LicenseScope` default settings (`default_max_usages`, `default_grace_days`) nullable so scopes inherit from config
- Added `default_max_usages` field to `LicenseTemplate`

#### Framework Support

- Added Laravel 13 support while maintaining Laravel 12 compatibility
- Added PHP 8.5 support
- Updated `orchestra/testbench` to support `^10.5 || ^11.0`
- Updated `symfony/uid` to support `^7.0 || ^8.0`
- Updated `spatie/laravel-sluggable` to support `^3.7 || ^3.8`
- GitHub Actions CI matrix now tests against both Laravel 12 and 13

#### Documentation

- Updated all key format examples to reflect new 128-bit hex format
- Added rate limiting section with endpoint/limiter mapping to configuration docs
- Updated security docs with new validation patterns
- Added UPGRADE.md with migration guide for breaking changes

## 1.1.0 - 2026-03-12

### What's Changed

#### Security Improvements

- **Stronger KDF**: Replaced single-round SHA-256 key derivation with `sodium_crypto_pwhash` (Argon2id, INTERACTIVE cost) for private key encryption, providing strong brute-force resistance
- **Versioned encryption format**: New v2 payload format (`0x02 + salt + nonce + ciphertext`) with full backward compatibility for existing v1-encrypted keys — no migration required
- **Memory cleanup**: Derived encryption keys are now wiped from memory via `sodium_memzero` immediately after use

#### Improvements

- **Octane & Queue compatibility**: Cached passphrase is automatically cleared after each request/job via event listeners on `RequestTerminated`, `TaskTerminated` (Octane) and `JobProcessed`, `JobFailed`, `WorkerStopping` (Queue)

#### Documentation

- Updated security architecture docs with v2 encryption format details
- Added Octane/Queue compatibility notes to key management docs

### Full Changelog

https://github.com/masterix21/laravel-licensing/compare/1.0.4...1.1.0

## 1.0.4 - 2026-03-10

### What's Changed

#### Bug Fixes

- **Passphrase resolution**: Fixed passphrase resolution failing after `artisan config:cache`
- **Ed25519 key handling**: Fixed Ed25519 key handling for Windows CI compatibility

#### Improvements

- **Code style**: Applied uniform code styling across models, services, events, and tests
- **PHPStan**: Fixed all 402 PHPStan errors (from 402 to 0) for full static analysis compliance
- **Test coverage**: Added regression tests ensuring all API route controller classes exist and `route:list` runs without errors

#### Dependencies

- Bumped `actions/checkout` from 5 to 6
- Bumped `dependabot/fetch-metadata` from 2.4.0 to 2.5.0
- Bumped `stefanzweifel/git-auto-commit-action` from 6 to 7

### Full Changelog

https://github.com/masterix21/laravel-licensing/compare/1.0.3...1.0.4

## 1.0.3 - 2025-09-18

### What's Changed

#### New Features

- **License Templates**: Added support for license templates with trial periods and custom durations
  - New `LicenseTemplate` model for managing reusable license configurations
  - Support for trial days and duration months in templates
  - Database migrations for template management

#### Improvements

- **CLI Commands**: Enhanced command-line interface with better user experience
  - Improved passphrase handling with interactive prompts when missing
  - Better error messages and output formatting
  - Enhanced test coverage for all CLI commands
  - More robust key management commands

#### Documentation

- Added references to companion packages in README:
  - `masterix21/laravel-licensing-client`: Client package for Laravel applications
  - `masterix21/laravel-licensing-filament-manager`: Filament UI for license management
- Expanded documentation for license templates and trial features

#### Bug Fixes

- Fixed CLI command output messages for better test compatibility
- Corrected issues with key passphrase prompting
- Improved error handling in key rotation commands

#### Testing

- Significantly improved test coverage for CLI commands
- Added comprehensive tests for license templates
- Enhanced test helpers for better test reliability

#### Dependencies

- Updated internal dependencies for better compatibility

### Full Changelog

https://github.com/masterix21/laravel-licensing/compare/1.0.2...1.0.3

## 1.0.2 - 2025-09-17

1.0.2

## 1.0.1 - 2025-09-15

### What's New in 1.0.1

#### License Scopes for Multi-Product Support

This release introduces **License Scopes**, enabling management of multiple products or software applications with isolated signing keys.

#### New Features

- **License Scopes Model**: New `LicenseScope` entity for product/software segregation
- **Scoped Signing Keys**: Each product can have its own signing keys with independent rotation schedules
- **CLI Support**: Issue scoped signing keys with `--scope` option
- **Automatic Key Selection**: Tokens are automatically signed with the correct scope-specific key
- **Fallback Support**: Graceful fallback to global keys when scoped keys are not available

#### Improvements

- Enhanced README with License Scope examples and documentation
- Added 6 new tests covering all scope functionality
- Updated services to support scope-based key selection

#### Technical Details

- Added `license_scope_id` to licenses table
- Added `license_scope_id` to licensing_keys table
- New `license_scopes` table with rotation configuration
- Updated `PasetoTokenService` for scope-aware key selection
- Enhanced `CertificateAuthorityService` to include scope in certificates

#### Usage Example

```php
// Create a scope for your product
$scope = LicenseScope::create([
    'name' => 'My Product',
    'slug' => 'my-product',
    'identifier' => 'com.company.product',
    'key_rotation_days' => 90,
]);

// Issue a scoped signing key
// php artisan licensing:keys:issue-signing --scope my-product

// Create a scoped license
$license = License::create([
    'key_hash' => License::hashKey($key),
    'license_scope_id' => $scope->id,
    // ... other fields
]);
```

#### Backward Compatibility

This release is fully backward compatible. License Scopes are optional — existing licenses without scopes will continue to work with global signing keys.

**Full Changelog**: https://github.com/masterix21/laravel-licensing/compare/1.0...1.0.1

## 1.0.0 - 2025-09-15

### Features

#### Core Licensing System

- **Polymorphic licensing** — attach licenses to any Laravel model
- **Seat-based licensing** — control device/usage limits with fingerprinting
- **License templates** — hierarchical templates with feature inheritance
- **Trial management** — complete trial lifecycle with conversion tracking
- **License transfers** — multi-party approval workflow for transfers
- **Renewals** — period-based renewal system with history tracking

#### Security & Cryptography

- **Offline verification** — PASETO v4 tokens for client-side validation
- **Two-level key hierarchy** — Root CA → Signing Keys architecture
- **Ed25519 signatures** — modern, fast cryptographic signatures
- **Key rotation** — built-in rotation with revocation support
- **Tamper-evident audit trail** — hash-chained logging system

#### Developer Experience

- **CLI** — 7 commands for key and license management
- **Contract-based design** — easy to extend and customize
- **Event-driven architecture** — 20+ events for hooking into operations
- **Full test coverage** — 180+ tests with security scenarios

### Requirements

- PHP 8.3+
- Laravel 12+
- OpenSSL and Sodium extensions

### Installation

```bash
composer require masterix21/laravel-licensing
php artisan vendor:publish --provider="LucaLongo\Licensing\LicensingServiceProvider"
php artisan migrate
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing
```

MIT License — see LICENSE.md for details.
