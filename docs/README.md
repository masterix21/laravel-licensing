# Laravel Licensing Documentation

> Licensing system for Laravel with offline verification, trial management, seat control, and cryptographic key rotation.

## Documentation Index

### Getting Started
- [Quick Start](getting-started.md) — up and running in 5 minutes
- [Installation](installation.md) — detailed setup instructions
- [Configuration](configuration.md) — configure the package for your needs
- [Basic Usage](basic-usage.md) — common use cases and examples

### Core Concepts
- [Licenses](core/licenses.md) — license management and lifecycle
- [Usage & Seats](core/usage-seats.md) — usage registration and seat management
- [Templates & Tiers](core/templates-tiers.md) — template-based licensing
- [Renewals](core/renewals.md) — license renewal system

### Features
- [Offline Verification](features/offline-verification.md) — offline token system with PASETO
- [Trial Management](features/trials.md) — trial licenses and conversion
- [License Transfers](features/transfers.md) — transfer licenses between entities
- [Audit Logging](features/audit-logging.md) — append-only audit trail
- [Scope Templates](features/scope-templates.md) — scope-aware template management

### API Reference
- [Models](api/models.md) — complete model reference
- [Services](api/services.md) — service layer documentation
- [Events](api/events.md) — event system reference
- [Commands](api/commands.md) — CLI commands reference
- [Contracts](api/contracts.md) — interface documentation
- [Enums](api/enums.md) — enumeration reference

### Advanced Topics
- [Security](advanced/security.md) — security architecture and best practices
- [Key Management](advanced/key-management.md) — cryptographic key lifecycle
- [Multi-Software Keys](advanced/multi-software-keys.md) — scoped signing keys for multiple products
- [Performance](advanced/performance.md) — optimization and scaling

### Guides & Examples
- [Practical Examples](examples/practical-examples.md) — real-world implementation examples

### Client Libraries
- [Client Library Architecture](client-libraries/architecture.md) — design principles for client libraries
- [Client Implementation Guide](CLIENT_IMPLEMENTATION_GUIDE.md) — complete client specification

### Reference
- [FAQ](reference/faq.md) — frequently asked questions
- [Troubleshooting](reference/troubleshooting.md) — common issues and solutions

## Key Features

### Security
- Ed25519 cryptography for offline token signing
- Two-level key hierarchy (Root → Signing) for safe key rotation
- Constant-time comparisons to prevent timing attacks
- Salted key hashing with application-specific salt
- Immutable audit trail with hash chaining

### Offline Capability
- PASETO v4 tokens for secure offline verification
- Certificate chains for trust validation
- Configurable TTL and force-online windows
- Clock skew tolerance for time synchronization issues

### Licensing Features
- Polymorphic licensing — attach licenses to any model
- Template system — hierarchical license templates with inheritance
- Transfer workflow — multi-party approval for license transfers
- Trial management — complete trial lifecycle with conversion tracking
- Usage policies — configurable over-limit and grace period handling

### Developer Experience
- Contract-based design — easy to extend and customize
- Event-driven architecture — hook into any licensing operation
- CLI for key lifecycle and license management
- Full test coverage with Pest PHP

## Quick Example

```php
use LucaLongo\Licensing\Models\{LicenseScope, LicenseTemplate};
use LucaLongo\Licensing\Services\TemplateService;

$scope = LicenseScope::firstOrCreate([
    'slug' => 'crm-platform',
], [
    'name' => 'CRM Platform',
    'identifier' => 'com.company.crm',
]);

$template = LicenseTemplate::updateOrCreate([
    'license_scope_id' => $scope->id,
    'name' => 'Professional Plan',
], [
    'tier_level' => 2,
    'base_configuration' => [
        'max_usages' => 5,
        'validity_days' => 365,
    ],
    'features' => [
        'advanced-analytics' => true,
        'api-access' => true,
    ],
    'entitlements' => [
        'api_calls_per_month' => 10000,
    ],
]);

$license = app(TemplateService::class)->createLicenseForScope($scope, $template->slug, [
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'key_hash' => License::hashKey($activationKey),
]);

$license->activate();

$usage = $license->usages()->create([
    'usage_fingerprint' => $deviceFingerprint,
    'name' => 'John\'s MacBook Pro',
]);

if ($license->hasFeature('advanced-analytics')) {
    // Enable advanced features
}

$apiCallsLimit = $license->getEntitlement('api_calls_per_month');
```

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `ext-openssl` and `ext-sodium`

## License

MIT. See [LICENSE.md](../LICENSE.md).
