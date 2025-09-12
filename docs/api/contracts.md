# API Reference: Contracts

This document provides comprehensive API reference for all contracts (interfaces) in the Laravel Licensing package. Contracts define the public API for services and allow for custom implementations.

## Table of Contents

- [Core Contracts](#core-contracts)
- [Service Contracts](#service-contracts)
- [Event Contracts](#event-contracts)
- [Custom Implementations](#custom-implementations)

## Core Contracts

### UsageRegistrar

Interface for managing license usage registration and seat consumption.

```php
namespace LucaLongo\Licensing\Contracts;

interface UsageRegistrar
{
    /**
     * Register a new usage (consume a seat)
     */
    public function register(License $license, array $data): LicenseUsage;
    
    /**
     * Revoke an existing usage (free a seat)
     */
    public function revoke(License $license, string $usageFingerprint): bool;
    
    /**
     * Update usage activity timestamp (heartbeat)
     */
    public function heartbeat(License $license, string $usageFingerprint): bool;
}
```

### TokenIssuer

Interface for issuing offline verification tokens.

```php
namespace LucaLongo\Licensing\Contracts;

interface TokenIssuer
{
    /**
     * Issue an offline token for license verification
     */
    public function issue(License $license, string $usageFingerprint, array $claims = []): string;
}
```

### TokenVerifier

Interface for verifying offline tokens.

```php
namespace LucaLongo\Licensing\Contracts;

interface TokenVerifier
{
    /**
     * Verify and decode an offline token
     */
    public function verify(string $token): array;
}
```

### KeyStore

Interface for cryptographic key storage and management.

```php
namespace LucaLongo\Licensing\Contracts;

interface KeyStore
{
    /**
     * Store a key pair
     */
    public function store(string $kid, array $keyData): bool;
    
    /**
     * Retrieve key by identifier
     */
    public function retrieve(string $kid): ?array;
    
    /**
     * Check if key exists
     */
    public function exists(string $kid): bool;
    
    /**
     * Delete a key
     */
    public function delete(string $kid): bool;
    
    /**
     * List all keys
     */
    public function list(): array;
}
```

### CertificateAuthority

Interface for managing certificate authority operations.

```php
namespace LucaLongo\Licensing\Contracts;

interface CertificateAuthority
{
    /**
     * Generate root key pair
     */
    public function generateRootKey(): string;
    
    /**
     * Issue a signing key
     */
    public function issueSigningKey(
        string $kid, 
        ?\DateTime $notBefore = null, 
        ?\DateTime $notAfter = null
    ): LicensingKey;
    
    /**
     * Revoke a key
     */
    public function revokeKey(string $kid, ?\DateTime $revokedAt = null): bool;
    
    /**
     * Rotate signing key
     */
    public function rotateSigningKey(string $reason = 'routine'): LicensingKey;
    
    /**
     * Get active signing key
     */
    public function getActiveSigningKey(): ?LicensingKey;
    
    /**
     * Export public key materials
     */
    public function exportPublicKeys(string $format = 'json'): string;
}
```

### AuditLogger

Interface for audit logging operations.

```php
namespace LucaLongo\Licensing\Contracts;

interface AuditLogger
{
    /**
     * Log an audit event
     */
    public function log(
        AuditEventType $eventType, 
        ?Model $entity = null, 
        array $data = [], 
        ?Model $actor = null
    ): void;
    
    /**
     * Retrieve audit logs for an entity
     */
    public function getLogsFor(Model $entity): Collection;
    
    /**
     * Retrieve logs by event type
     */
    public function getLogsByType(AuditEventType $eventType): Collection;
}
```

### FingerprintResolver

Interface for generating usage fingerprints.

```php
namespace LucaLongo\Licensing\Contracts;

interface FingerprintResolver
{
    /**
     * Resolve fingerprint from context data
     */
    public function resolve(array $context = []): string;
}
```

## Service Contracts

### CanInitiateLicenseTransfers

Interface for models that can initiate license transfers.

```php
namespace LucaLongo\Licensing\Contracts;

interface CanInitiateLicenseTransfers
{
    /**
     * Initiate a license transfer
     */
    public function initiateLicenseTransfer(License $license, array $data): LicenseTransfer;
    
    /**
     * Get initiated transfers
     */
    public function initiatedTransfers(): MorphMany;
}
```

### CanReceiveLicenseTransfers

Interface for models that can receive license transfers.

```php
namespace LucaLongo\Licensing\Contracts;

interface CanReceiveLicenseTransfers
{
    /**
     * Accept a license transfer
     */
    public function acceptLicenseTransfer(LicenseTransfer $transfer): bool;
    
    /**
     * Get received transfers
     */
    public function receivedTransfers(): MorphMany;
}
```

### AuditLog

Interface for audit log entries.

```php
namespace LucaLongo\Licensing\Contracts;

interface AuditLog
{
    /**
     * Get the event type
     */
    public function getEventType(): AuditEventType;
    
    /**
     * Get the affected entity
     */
    public function getEntity(): ?Model;
    
    /**
     * Get the actor who performed the action
     */
    public function getActor(): ?Model;
    
    /**
     * Get event data
     */
    public function getData(): array;
    
    /**
     * Verify hash chain integrity
     */
    public function verifyHashChain(): bool;
}
```

## Custom Implementations

### Implementing UsageRegistrar

```php
class CustomUsageRegistrar implements UsageRegistrar
{
    public function register(License $license, array $data): LicenseUsage
    {
        // Validate license
        if (!$license->isUsable()) {
            throw new LicenseNotUsableException($license);
        }
        
        // Check seat availability
        if (!$license->hasAvailableSeats()) {
            throw new UsageLimitReachedException($license);
        }
        
        // Custom registration logic
        return DB::transaction(function() use ($license, $data) {
            $usage = $license->usages()->create([
                'usage_fingerprint' => $data['usage_fingerprint'],
                'status' => 'active',
                'registered_at' => now(),
                'last_seen_at' => now(),
                'client_type' => $data['client_type'] ?? null,
                'name' => $data['name'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);
            
            // Custom business logic
            $this->notifyUsageRegistered($usage);
            
            return $usage;
        });
    }
    
    public function revoke(License $license, string $usageFingerprint): bool
    {
        $usage = $license->usages()
            ->where('usage_fingerprint', $usageFingerprint)
            ->where('status', 'active')
            ->first();
        
        if (!$usage) {
            return false;
        }
        
        $usage->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
        
        return true;
    }
    
    public function heartbeat(License $license, string $usageFingerprint): bool
    {
        return $license->usages()
            ->where('usage_fingerprint', $usageFingerprint)
            ->where('status', 'active')
            ->update(['last_seen_at' => now()]) > 0;
    }
    
    private function notifyUsageRegistered(LicenseUsage $usage): void
    {
        // Custom notification logic
    }
}
```

### Implementing KeyStore

```php
class DatabaseKeyStore implements KeyStore
{
    public function store(string $kid, array $keyData): bool
    {
        try {
            LicensingKey::updateOrCreate(
                ['kid' => $kid],
                [
                    'type' => $keyData['type'],
                    'status' => $keyData['status'],
                    'public_key' => $keyData['public_key'],
                    'private_key' => $keyData['private_key'] ?? null,
                    'not_before' => $keyData['not_before'] ?? null,
                    'not_after' => $keyData['not_after'] ?? null,
                    'meta' => $keyData['meta'] ?? null,
                ]
            );
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function retrieve(string $kid): ?array
    {
        $key = LicensingKey::where('kid', $kid)->first();
        
        if (!$key) {
            return null;
        }
        
        return [
            'kid' => $key->kid,
            'type' => $key->type,
            'status' => $key->status,
            'public_key' => $key->public_key,
            'private_key' => $key->private_key,
            'not_before' => $key->not_before,
            'not_after' => $key->not_after,
            'meta' => $key->meta,
        ];
    }
    
    public function exists(string $kid): bool
    {
        return LicensingKey::where('kid', $kid)->exists();
    }
    
    public function delete(string $kid): bool
    {
        return LicensingKey::where('kid', $kid)->delete() > 0;
    }
    
    public function list(): array
    {
        return LicensingKey::all()
            ->map(fn($key) => [
                'kid' => $key->kid,
                'type' => $key->type,
                'status' => $key->status,
                'created_at' => $key->created_at,
            ])
            ->toArray();
    }
}
```

### Implementing FingerprintResolver

```php
class MobileAppFingerprintResolver implements FingerprintResolver
{
    public function resolve(array $context = []): string
    {
        $components = [
            $context['device_id'] ?? '',
            $context['app_bundle_id'] ?? '',
            $context['installation_uuid'] ?? '',
            $context['app_version'] ?? '',
        ];
        
        // Validate required components
        if (empty($components[0]) || empty($components[2])) {
            throw new InvalidArgumentException('Device ID and Installation UUID required');
        }
        
        // Create stable fingerprint
        $composite = implode('|', array_filter($components));
        
        return hash('sha256', $composite);
    }
}
```

### Implementing AuditLogger

```php
class CustomAuditLogger implements AuditLogger
{
    public function log(
        AuditEventType $eventType, 
        ?Model $entity = null, 
        array $data = [], 
        ?Model $actor = null
    ): void {
        $logData = [
            'event_type' => $eventType,
            'entity_type' => $entity ? get_class($entity) : null,
            'entity_id' => $entity?->id,
            'actor_type' => $actor ? get_class($actor) : null,
            'actor_id' => $actor?->id,
            'data' => $data,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ];
        
        // Add hash chain for tamper-evidence
        $previousLog = LicensingAuditLog::latest()->first();
        if ($previousLog) {
            $logData['hash_chain'] = hash('sha256', $previousLog->hash_chain . json_encode($logData));
        } else {
            $logData['hash_chain'] = hash('sha256', json_encode($logData));
        }
        
        LicensingAuditLog::create($logData);
        
        // Send to external logging service
        $this->sendToExternalLogger($logData);
    }
    
    public function getLogsFor(Model $entity): Collection
    {
        return LicensingAuditLog::where('entity_type', get_class($entity))
            ->where('entity_id', $entity->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    public function getLogsByType(AuditEventType $eventType): Collection
    {
        return LicensingAuditLog::where('event_type', $eventType)
            ->orderBy('created_at', 'desc')
            ->get();
    }
    
    private function sendToExternalLogger(array $data): void
    {
        // Implementation for external logging
    }
}
```

### Contract Registration

Register custom implementations in your service provider:

```php
class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Replace default implementations
        $this->app->bind(UsageRegistrar::class, CustomUsageRegistrar::class);
        $this->app->bind(KeyStore::class, DatabaseKeyStore::class);
        $this->app->bind(FingerprintResolver::class, MobileAppFingerprintResolver::class);
        $this->app->bind(AuditLogger::class, CustomAuditLogger::class);
    }
}
```

### Testing Custom Implementations

```php
class CustomUsageRegistrarTest extends TestCase
{
    public function test_registers_usage_successfully()
    {
        $license = License::factory()->active()->create(['max_usages' => 5]);
        $registrar = app(UsageRegistrar::class);
        
        $usage = $registrar->register($license, [
            'usage_fingerprint' => 'test-fingerprint',
            'client_type' => 'test-client',
            'name' => 'Test Usage',
        ]);
        
        $this->assertInstanceOf(LicenseUsage::class, $usage);
        $this->assertEquals('active', $usage->status);
        $this->assertEquals('test-fingerprint', $usage->usage_fingerprint);
    }
    
    public function test_throws_exception_when_license_not_usable()
    {
        $license = License::factory()->create(['status' => 'suspended']);
        $registrar = app(UsageRegistrar::class);
        
        $this->expectException(LicenseNotUsableException::class);
        
        $registrar->register($license, [
            'usage_fingerprint' => 'test-fingerprint',
        ]);
    }
}
```

## Contract Benefits

### 1. Flexibility
Contracts allow you to replace any component with custom implementations while maintaining compatibility.

### 2. Testability
Mock contracts in tests for isolated unit testing:

```php
class LicenseServiceTest extends TestCase
{
    public function test_activates_license()
    {
        $mockRegistrar = Mockery::mock(UsageRegistrar::class);
        $mockRegistrar->shouldReceive('register')->once()->andReturn(new LicenseUsage);
        
        $this->app->instance(UsageRegistrar::class, $mockRegistrar);
        
        // Test your service
    }
}
```

### 3. Extensibility
Extend functionality without modifying core package code:

```php
class EnhancedUsageRegistrar implements UsageRegistrar
{
    public function __construct(
        private UsageRegistrar $baseRegistrar,
        private NotificationService $notifications
    ) {}
    
    public function register(License $license, array $data): LicenseUsage
    {
        $usage = $this->baseRegistrar->register($license, $data);
        
        // Add custom functionality
        $this->notifications->notifyUsageRegistered($usage);
        
        return $usage;
    }
    
    // Delegate other methods to base implementation
    public function revoke(License $license, string $usageFingerprint): bool
    {
        return $this->baseRegistrar->revoke($license, $usageFingerprint);
    }
    
    public function heartbeat(License $license, string $usageFingerprint): bool
    {
        return $this->baseRegistrar->heartbeat($license, $usageFingerprint);
    }
}
```

### 4. Integration
Contracts make it easy to integrate with external systems:

```php
class StripeTokenIssuer implements TokenIssuer
{
    public function issue(License $license, string $usageFingerprint, array $claims = []): string
    {
        // Verify subscription status with Stripe
        $subscription = StripeService::getSubscription($license->meta['stripe_subscription_id']);
        
        if ($subscription['status'] !== 'active') {
            throw new TokenIssuanceException('Subscription not active');
        }
        
        // Issue token with Stripe metadata
        return parent::issue($license, $usageFingerprint, array_merge($claims, [
            'stripe_subscription_id' => $subscription['id'],
            'stripe_customer_id' => $subscription['customer'],
        ]));
    }
}
```

This comprehensive contract reference provides the foundation for customizing and extending the Laravel Licensing package to meet your specific requirements.