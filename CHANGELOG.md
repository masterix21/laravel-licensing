# Changelog

All notable changes to `laravel-licensing` will be documented in this file.

## 1.1.0 - 2026-03-12

### What's Changed

#### 🔐 Security Improvements

- **Stronger KDF**: Replaced single-round SHA-256 key derivation with `sodium_crypto_pwhash` (Argon2id, INTERACTIVE cost) for private key encryption, providing strong brute-force resistance
- **Versioned encryption format**: New v2 payload format (`0x02 + salt + nonce + ciphertext`) with full backward compatibility for existing v1-encrypted keys — no migration required
- **Memory cleanup**: Derived encryption keys are now wiped from memory via `sodium_memzero` immediately after use

#### 🔧 Improvements

- **Octane & Queue compatibility**: Cached passphrase is automatically cleared after each request/job via event listeners on `RequestTerminated`, `TaskTerminated` (Octane) and `JobProcessed`, `JobFailed`, `WorkerStopping` (Queue)

#### 📚 Documentation

- Updated security architecture docs with v2 encryption format details
- Added Octane/Queue compatibility notes to key management docs

### Full Changelog

https://github.com/masterix21/laravel-licensing/compare/1.0.4...1.1.0

## 1.0.4 - 2026-03-10

### What's Changed

#### 🐛 Bug Fixes

- **Passphrase resolution**: Fixed passphrase resolution failing after `artisan config:cache`
- **Ed25519 key handling**: Fixed Ed25519 key handling for Windows CI compatibility

#### 🔧 Improvements

- **Code style**: Applied uniform code styling across models, services, events, and tests
- **PHPStan**: Fixed all 402 PHPStan errors (from 402 → 0) for full static analysis compliance
- **Test coverage**: Added regression tests ensuring all API route controller classes exist and `route:list` runs without errors

#### 📦 Dependencies

- Bumped `actions/checkout` from 5 to 6
- Bumped `dependabot/fetch-metadata` from 2.4.0 to 2.5.0
- Bumped `stefanzweifel/git-auto-commit-action` from 6 to 7

### Full Changelog

https://github.com/masterix21/laravel-licensing/compare/1.0.3...1.0.4

## 1.0.3 - 2025-09-18

### What's Changed

#### ✨ New Features

- **License Templates**: Added support for license templates with trial periods and custom durations
  - New `LicenseTemplate` model for managing reusable license configurations
  - Support for trial days and duration months in templates
  - Database migrations for template management
  

#### 🔧 Improvements

- **CLI Commands**: Enhanced command-line interface with better user experience
  - Improved passphrase handling with interactive prompts when missing
  - Better error messages and output formatting
  - Enhanced test coverage for all CLI commands
  - More robust key management commands
  

#### 📚 Documentation

- Added references to companion packages in README:
  - `masterix21/laravel-licensing-client`: Client package for Laravel applications
  - `masterix21/laravel-licensing-filament-manager`: Filament UI for license management
  
- Expanded documentation for license templates and trial features
- Improved templates and tiers documentation

#### 🐛 Bug Fixes

- Fixed CLI command output messages for better test compatibility
- Corrected issues with key passphrase prompting
- Improved error handling in key rotation commands

#### 🧪 Testing

- Significantly improved test coverage for CLI commands
- Added comprehensive tests for license templates
- Enhanced test helpers for better test reliability

#### 📦 Dependencies

- Updated internal dependencies for better compatibility

### Full Changelog

https://github.com/masterix21/laravel-licensing/compare/1.0.2...1.0.3

## 1.0.2 - 2025-09-17

1.0.2

## v1.0.1 - 2025-09-15

### What's New in 1.0.1

#### 🎯 License Scopes for Multi-Product Support

This release introduces **License Scopes**, a powerful feature that enables you to manage multiple products or software applications with isolated signing keys.

#### ✨ New Features

- **License Scopes Model**: New `LicenseScope` entity for product/software segregation
- **Scoped Signing Keys**: Each product can have its own signing keys with independent rotation schedules
- **CLI Support**: Issue scoped signing keys with `--scope` option
- **Automatic Key Selection**: Tokens are automatically signed with the correct scope-specific key
- **Fallback Support**: Graceful fallback to global keys when scoped keys are not available

#### 📝 Improvements

- Enhanced README with License Scope examples and documentation
- Comprehensive AI guidelines updated with scope patterns for all platforms
- Added 6 new tests covering all scope functionality
- Updated services to support scope-based key selection

#### 🔧 Technical Details

- Added `license_scope_id` to licenses table
- Added `license_scope_id` to licensing_keys table
- New `license_scopes` table with rotation configuration
- Updated `PasetoTokenService` for scope-aware key selection
- Enhanced `CertificateAuthorityService` to include scope in certificates

#### 💡 Usage Example

```php
// Create a scope for your product
$scope = LicenseScope::create([
    'name' => 'My Product',
    'slug' => 'my-product',
    'identifier' => 'com.company.product',
    'key_rotation_days' => 90,
]);

// Issue a scoped signing key
php artisan licensing:keys:issue-signing --scope my-product

// Create a scoped license
$license = License::create([
    'key_hash' => License::hashKey($key),
    'license_scope_id' => $scope->id,
    // ... other fields
]);



```
#### 🔄 Backward Compatibility

This release is fully backward compatible. License Scopes are optional - existing licenses without scopes will continue to work with global signing keys.

#### 📚 Documentation

- Updated README with multi-product licensing section
- Comprehensive examples in AI_GUIDELINES.md
- New documentation in docs/advanced/multi-software-keys.md

**Full Changelog**: https://github.com/masterix21/laravel-licensing/compare/1.0...1.0.1

## Laravel Licensing v1.0.0 - 2025-09-15

### 🎉 Laravel Licensing v1.0.0 - Production Ready

#### ✨ Features

##### Core Licensing System

- **Polymorphic licensing** - Attach licenses to any Laravel model
- **Seat-based licensing** - Control device/usage limits with fingerprinting
- **License templates** - Hierarchical templates with feature inheritance
- **Trial management** - Complete trial lifecycle with conversion tracking
- **License transfers** - Multi-party approval workflow for transfers
- **Renewals** - Period-based renewal system with history tracking

##### Security & Cryptography

- **Offline verification** - PASETO v4 tokens for client-side validation
- **Two-level key hierarchy** - Root CA → Signing Keys architecture
- **Ed25519 signatures** - Modern, fast cryptographic signatures
- **Key rotation** - Built-in rotation with revocation support
- **Tamper-evident audit trail** - Hash-chained logging system

##### Developer Experience

- **Comprehensive CLI** - 7 commands for key and license management
- **Contract-based design** - Easy to extend and customize
- **Event-driven architecture** - 20+ events for hooking into operations
- **Full test coverage** - 180+ tests with security scenarios
- **AI assistant guidelines** - Support for Claude, ChatGPT, Copilot, Junie

#### 📚 Documentation

- Complete API reference
- Security architecture guide
- Getting started in 5 minutes
- 27 documentation files
- Practical examples and recipes

#### 🔧 Requirements

- PHP 8.2+
- Laravel 12.0+
- OpenSSL extension
- Sodium extension

#### 📦 Installation

```bash
composer require masterix21/laravel-licensing




```
#### 🚀 Quick Start

```bash
php artisan vendor:publish --provider="LucaLongo\Licensing\LicensingServiceProvider"
php artisan migrate
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing




```
#### 📄 License

MIT License - See LICENSE.md for details
