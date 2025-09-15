# Changelog

All notable changes to `laravel-licensing` will be documented in this file.

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
