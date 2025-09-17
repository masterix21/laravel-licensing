# Laravel Licensing Documentation

> Enterprise-grade licensing system for Laravel applications with offline verification, trial management, and comprehensive security features.

## 📚 Documentation Index

### Getting Started
- [**🚀 Quick Start**](getting-started.md) - Get up and running in 5 minutes
- [**📦 Installation**](installation.md) - Detailed installation instructions
- [**⚙️ Configuration**](configuration.md) - Configure the package for your needs
- [**🎯 Basic Usage**](basic-usage.md) - Common use cases and examples

### Core Concepts
- [**📜 Licenses**](core/licenses.md) - License management and lifecycle
- [**💺 Usage & Seats**](core/usage-seats.md) - Usage registration and seat management
- [**🎫 Templates & Tiers**](core/templates-tiers.md) - Template-based licensing
- [**🔄 Renewals**](core/renewals.md) - License renewal system

### Features
- [**🔐 Offline Verification**](features/offline-verification.md) - Offline token system with PASETO
- [**⏰ Trial Management**](features/trials.md) - Trial licenses and conversion
- [**🔄 License Transfers**](features/transfers.md) - Transfer licenses between entities
- [**📊 Audit Logging**](features/audit-logging.md) - Comprehensive audit trail

### API Reference
- [**📖 Models**](api/models.md) - Complete model reference
- [**🔧 Services**](api/services.md) - Service layer documentation
- [**📡 Events**](api/events.md) - Event system reference
- [**🎛️ Commands**](api/commands.md) - CLI commands reference
- [**🔌 Contracts**](api/contracts.md) - Interface documentation
- [**📝 Enums**](api/enums.md) - Enumeration reference

### Advanced Topics
- [**🔒 Security**](advanced/security.md) - Security architecture and best practices
- [**🔑 Key Management**](advanced/key-management.md) - Cryptographic key lifecycle
- [**🎯 Multi-Software Keys**](advanced/multi-software-keys.md) - Scoped signing keys for multiple products
- [**⚡ Performance**](advanced/performance.md) - Optimization and scaling
- [**🔧 Customization**](advanced/customization.md) - Extending the package

### Guides & Examples
- [**📚 Recipes**](examples/recipes.md) - Common implementation patterns
- [**🏗️ Integration Examples**](examples/integrations.md) - Third-party integrations
- [**💡 Best Practices**](examples/best-practices.md) - Recommended patterns
- [**💻 Practical Examples**](examples/practical-examples.md) - Real-world implementation examples
- [**🤖 AI Assistant Guidelines**](../AI_GUIDELINES.md) - Guidelines for AI coding assistants (Claude, ChatGPT, Copilot, Junie)

### Client Libraries
- [**📱 Client Library Architecture**](client-libraries/architecture.md) - Design principles for client libraries
- [**🔧 Implementation Guide**](client-libraries/implementation-guide.md) - Building clients in different languages
- [**🔌 API Integration**](client-libraries/api-integration.md) - Connecting to the licensing server
- [**🔐 Offline Verification**](client-libraries/offline-verification.md) - Implementing offline token verification

### Reference
- [**❓ FAQ**](reference/faq.md) - Frequently asked questions
- [**🔧 Troubleshooting**](reference/troubleshooting.md) - Common issues and solutions
- [**📝 Changelog**](reference/changelog.md) - Version history
- [**🔄 Migration Guide**](reference/migration.md) - Upgrading between versions

## 🌟 Key Features

### 🔐 Security-First Design
- **Ed25519 cryptography** for offline token signing
- **Two-level key hierarchy** (Root → Signing) for secure key rotation
- **Constant-time comparisons** to prevent timing attacks
- **Salted key hashing** with application-specific salt
- **Immutable audit trail** with hash chaining

### 🌐 Offline Capability
- **PASETO v4 tokens** for secure offline verification
- **Certificate chains** for trust validation
- **Configurable TTL** and force-online windows
- **Clock skew tolerance** for time synchronization issues

### 📦 Enterprise Features
- **Polymorphic licensing** - Attach licenses to any model
- **Template system** - Hierarchical license templates with inheritance
- **Transfer workflow** - Multi-party approval for license transfers
- **Trial management** - Complete trial lifecycle with conversion tracking
- **Usage policies** - Configurable over-limit and grace period handling

### 🎯 Developer Experience
- **Contract-based design** - Easy to extend and customize
- **Event-driven architecture** - Hook into any licensing operation
- **Comprehensive CLI** - Manage keys and licenses from command line
- **Extensive testing** - Full test coverage with Pest PHP

## 🚀 Quick Example

```php
use LucaLongo\Licensing\Models\{LicenseScope, LicenseTemplate};
use LucaLongo\Licensing\Services\TemplateService;

// Ensure the product scope and template exist
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

// Provision a scoped license using the template service
$license = app(TemplateService::class)->createLicenseForScope($scope, $template->slug, [
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'key_hash' => License::hashKey($activationKey),
]);

$license->activate();

// Register a device/usage
$usage = $license->usages()->create([
    'usage_fingerprint' => $deviceFingerprint,
    'name' => 'John\'s MacBook Pro',
]);

if ($license->hasFeature('advanced-analytics')) {
    // Enable advanced features
}

$apiCallsLimit = $license->getEntitlement('api_calls_per_month');
```

## 📋 Requirements

- PHP 8.2+
- Laravel 12.0+
- OpenSSL extension
- Sodium extension (for PASETO tokens and Ed25519 signatures)

## 🤝 Support

- **Issues**: [GitHub Issues](https://github.com/lucalongo/laravel-licensing/issues)
- **Discussions**: [GitHub Discussions](https://github.com/lucalongo/laravel-licensing/discussions)
- **Email**: support@laravel-licensing.com

## 📄 License

Laravel Licensing is open-sourced software licensed under the [MIT license](LICENSE.md).
