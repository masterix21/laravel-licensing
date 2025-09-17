# Changelog

All notable changes to `laravel-licensing` will be documented in this file.

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
