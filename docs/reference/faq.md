# ❓ Frequently Asked Questions

Answers to common questions about Laravel Licensing.

## Table of Contents

- [General Questions](#general-questions)
- [Licensing Model](#licensing-model)
- [Security & Cryptography](#security--cryptography)
- [Offline Verification](#offline-verification)
- [Performance & Scalability](#performance--scalability)
- [Integration & Customization](#integration--customization)
- [Troubleshooting](#troubleshooting)

## General Questions

### What is Laravel Licensing?

Laravel Licensing is a comprehensive licensing system for Laravel applications that provides:
- License key generation and validation
- Device/seat management
- Offline verification using cryptographic tokens
- Template-based licensing tiers
- Grace periods and expiration handling
- Trial license management
- Audit logging and compliance

### Who should use this package?

This package is ideal for:
- **SaaS applications** requiring tiered subscriptions
- **Desktop software** needing offline license verification
- **Mobile apps** with device limits
- **Enterprise software** with seat-based licensing
- **API services** with usage quotas
- **Any application** requiring robust license management

### Is it production-ready?

Yes. The package includes:
- Comprehensive test coverage
- Security-first design
- Performance optimizations
- Production deployment guides
- Audit logging
- GDPR compliance features

### What are the system requirements?

- **PHP**: 8.1 or higher
- **Laravel**: 10.0 or higher
- **Database**: MySQL 8.0+, PostgreSQL 12+, or SQLite
- **Extensions**: OpenSSL, Sodium (for PASETO)
- **Optional**: Redis for caching

## Licensing Model

### How does polymorphic licensing work?

Licenses can be attached to any model in your application:

```php
// License for a User
$license->licensable_type = User::class;
$license->licensable_id = $user->id;

// License for an Organization
$license->licensable_type = Organization::class;
$license->licensable_id = $org->id;

// License for a Device
$license->licensable_type = Device::class;
$license->licensable_id = $device->id;
```

### What's the difference between a license and a usage?

- **License**: The entitlement itself (like a software license key)
- **Usage**: A specific activation/seat (like a device using the license)

One license can have multiple usages up to `max_usages`.

### How do templates and tiers work?

Templates define reusable license configurations:

```php
$template = LicenseTemplate::create([
    'name' => 'Professional',
    'max_usages' => 5,
    'duration_days' => 365,
    'features' => ['api_access', 'priority_support'],
    'entitlements' => ['api_calls' => 10000],
]);

// Create license from template
$license = License::createFromTemplate($template, $user);
```

### Can I have multiple active licenses?

Yes. You can configure how multiple licenses interact:
- **Additive**: Features and limits combine
- **Override**: Most recent/highest tier takes precedence
- **Selective**: Choose which license to use per operation

### How do grace periods work?

When a license expires:
1. Status changes from `active` → `grace`
2. Grace period lasts for configured days (default: 14)
3. During grace, functionality can be limited
4. After grace, status becomes `expired`

Configure in `config/licensing.php`:
```php
'policies' => [
    'grace_days' => 14,
    'grace_restrictions' => [
        'read_only' => false,
        'limited_features' => true,
    ],
],
```

## Security & Cryptography

### What cryptographic algorithms are used?

- **Default**: Ed25519 (EdDSA) for signatures
- **Alternative**: ES256 (ECDSA with P-256)
- **Token format**: PASETO v4 (default) or JWS
- **Key derivation**: PBKDF2 for passphrase-based encryption
- **Hashing**: SHA-256 with salt for activation keys

### How are activation keys stored?

Activation keys are **never** stored in plain text:
1. Generated key: `XXXX-XXXX-XXXX-XXXX`
2. Salted hash stored: `SHA256(salt + key)`
3. Constant-time comparison during verification

### What is the two-level key hierarchy?

```
Root Key (long-lived, 2-5 years)
    ↓ signs
Signing Keys (short-lived, 30-90 days)
    ↓ sign
Offline Tokens (very short, 7-14 days)
```

Benefits:
- Root key rarely used (reduced exposure)
- Signing keys rotatable without breaking trust
- Compromise containment

### How secure is offline verification?

Very secure when configured properly:
- Tokens are cryptographically signed
- Client only needs public keys
- No network request required
- Short TTL limits exposure
- Certificate chains prevent tampering

### Can the system detect tampering?

Yes, multiple layers:
- Cryptographic signatures on tokens
- Hash-chained audit logs
- Integrity checks on key material
- Fingerprint validation
- Usage anomaly detection

## Offline Verification

### How does offline verification work?

1. **Server issues token** with license data and signature
2. **Client stores token** locally (encrypted)
3. **Client verifies** using public key (no network)
4. **Token expires** after TTL (typically 7 days)
5. **Client refreshes** when online

### What happens if a client can't connect?

Clients can work offline until token expires:
- Default TTL: 7 days
- Configurable grace period after expiration
- Force online check after N days (configurable)
- Degraded mode options available

### How large are offline tokens?

Typical sizes:
- **PASETO token**: 400-600 bytes
- **With entitlements**: 800-1200 bytes
- **With full metadata**: 1500-2000 bytes

Compression can reduce by ~40%.

### Can tokens be shared between devices?

No. Tokens include:
- Device fingerprint binding
- Usage-specific claims
- Non-transferable signatures

Attempting to use on wrong device fails verification.

### How often should tokens be refreshed?

Recommended refresh strategy:
- **TTL**: 7 days
- **Refresh after**: 5 days (while still valid)
- **Force online**: Every 14-30 days
- **On app launch**: If token expires within 24h

## Performance & Scalability

### How many licenses can the system handle?

Tested configurations:
- **Small**: 10,000 licenses, 50,000 usages
- **Medium**: 100,000 licenses, 500,000 usages
- **Large**: 1M+ licenses, 5M+ usages

With proper indexing and caching.

### What are the performance bottlenecks?

Main considerations:
1. **Usage registration**: Uses pessimistic locking
2. **Token generation**: CPU-bound (crypto operations)
3. **Expiration checks**: Can be heavy with many licenses
4. **Audit logging**: I/O intensive

Mitigations provided for each.

### How can I optimize for high load?

```php
// Use caching
'cache' => [
    'enabled' => true,
    'ttl' => 300, // 5 minutes
    'driver' => 'redis',
],

// Batch operations
License::whereIn('id', $ids)
    ->chunkById(1000, function ($licenses) {
        // Process batch
    });

// Queue heavy operations
ProcessExpirationsJob::dispatch()->onQueue('low');
```

### What about database performance?

Key optimizations:
- Composite indexes on hot paths
- Partial indexes for status queries
- Read replicas for validation
- Connection pooling
- Query result caching

### Can it handle concurrent usage registration?

Yes, using pessimistic locking:
```php
DB::transaction(function () use ($license) {
    $license->lockForUpdate();
    
    if ($license->canRegisterUsage()) {
        // Safe to register
    }
});
```

## Integration & Customization

### Can I use custom models?

Yes, via configuration:
```php
'models' => [
    'license' => App\Models\CustomLicense::class,
    'usage' => App\Models\CustomUsage::class,
],
```

Your models must implement the package contracts.

### How do I add custom validation rules?

```php
// In a service provider
License::validating(function ($license) {
    if ($license->licensable_type === User::class) {
        $user = User::find($license->licensable_id);
        
        if ($user->country === 'restricted') {
            throw new ValidationException('License not available in this region');
        }
    }
});
```

### Can I customize the activation key format?

Yes, implement `ActivationKeyGenerator`:
```php
class CustomKeyGenerator implements ActivationKeyGenerator
{
    public function generate(): string
    {
        // Your format: ABC-123-XYZ
        return sprintf('%s-%d-%s',
            Str::random(3),
            random_int(100, 999),
            Str::random(3)
        );
    }
}

// Register in service provider
$this->app->bind(ActivationKeyGenerator::class, CustomKeyGenerator::class);
```

### How do I integrate with my billing system?

```php
// Listen to license events
Event::listen(LicenseActivated::class, function ($event) {
    // Update billing system
    BillingSystem::recordActivation($event->license);
});

Event::listen(LicenseRenewed::class, function ($event) {
    // Process renewal payment
    BillingSystem::processRenewal($event->renewal);
});
```

### Can I use this with Laravel Spark/Cashier?

Yes, integrate with subscriptions:
```php
// When Spark subscription created
$user->spark_subscription->whenCreated(function ($subscription) {
    License::createForSubscription($subscription, $this->user);
});

// Sync with Cashier
$user->subscribed('default') && $user->license->activate();
```

## Troubleshooting

### Why is my license not activating?

Common causes:
1. **Invalid key format** - Check validation rules
2. **Key already used** - Each key is single-use
3. **License expired** - Check `expires_at`
4. **Max usages reached** - Check seat limit
5. **Validation failing** - Check custom rules

Debug:
```php
try {
    $license->activate();
} catch (LicenseException $e) {
    Log::error('Activation failed', [
        'reason' => $e->getMessage(),
        'license' => $license->toArray(),
    ]);
}
```

### Token verification is failing offline

Check:
1. **Public key present** - Client needs public key bundle
2. **Clock sync** - Allow ±60s skew
3. **Token not expired** - Check TTL
4. **Correct algorithm** - PASETO vs JWS
5. **Fingerprint matches** - Device hasn't changed

### Database queries are slow

Optimize:
```sql
-- Add indexes
CREATE INDEX idx_licenses_status_expires 
ON licenses(status, expires_at);

CREATE INDEX idx_usages_license_status 
ON license_usages(license_id, status);

-- Analyze query plans
EXPLAIN SELECT * FROM licenses 
WHERE status = 'active' 
AND expires_at > NOW();
```

### How do I debug key rotation issues?

```bash
# Check key status
php artisan licensing:keys:list --show-revoked

# Verify chain
php artisan licensing:keys:verify-chain

# Test token with new key
php artisan licensing:offline:issue --test
php artisan licensing:offline:verify <token>
```

### Memory usage is high

Common causes:
- Loading too many licenses at once
- Not using chunking for batch operations
- Audit log queries without limits
- Token generation in tight loops

Solution:
```php
// Use chunking
License::where('status', 'active')
    ->chunk(100, function ($licenses) {
        // Process small batch
    });

// Limit audit queries
AuditLog::recent()->limit(1000)->get();
```

### How do I handle GDPR data requests?

```php
// Data export
$userData = License::where('licensable_type', User::class)
    ->where('licensable_id', $user->id)
    ->with(['usages', 'renewals', 'auditLogs'])
    ->get()
    ->toJson();

// Data deletion (soft delete)
$user->licenses->each->delete();
$user->licenseUsages->each->delete();

// Hard delete (if required)
DB::table('licenses')
    ->where('licensable_type', User::class)
    ->where('licensable_id', $user->id)
    ->delete();
```

## Getting Help

### Where can I find more documentation?

- [GitHub Repository](https://github.com/masterix21/laravel-licensing)
- [API Documentation](../api/models.md)
- [Security Guide](../advanced/security.md)
- [Troubleshooting Guide](troubleshooting.md)

### How do I report bugs?

1. Check existing issues on GitHub
2. Create minimal reproduction case
3. Include:
   - Laravel version
   - Package version
   - Error messages
   - Stack traces
4. Submit issue with details

### Can I contribute?

Yes! We welcome contributions:
- Bug fixes
- Feature additions
- Documentation improvements
- Test coverage
- Security audits

See [CONTRIBUTING.md](https://github.com/masterix21/laravel-licensing/blob/main/CONTRIBUTING.md).

### Is commercial support available?

Contact options:
- GitHub Sponsors for priority support
- Enterprise support contracts available
- Consulting for custom implementations
- Training workshops

### How do I stay updated?

- Watch GitHub repository for releases
- Follow changelog for updates
- Subscribe to security advisories
- Join community discussions

## Next Steps

- [Installation Guide](../installation.md) - Get started
- [Configuration](../configuration.md) - Customize settings
- [Basic Usage](../basic-usage.md) - Common operations
- [Troubleshooting](troubleshooting.md) - Solve problems