# API Reference: Models

This document provides comprehensive API reference for all models in the Laravel Licensing package. Each model includes properties, relationships, methods, scopes, and usage examples.

## Table of Contents

- [License](#license)
- [LicenseUsage](#licenseusage)
- [LicenseRenewal](#licenserenewal)
- [LicenseTemplate](#licensetemplate)
- [LicenseTrial](#licensetrial)
- [LicenseTransfer](#licensetransfer)
- [LicenseTransferHistory](#licensetransferhistory)
- [LicenseTransferApproval](#licensetransferapproval)
- [LicensingKey](#licensingkey)
- [LicensingAuditLog](#licensingauditlog)

## License

The primary model representing a software license.

### Properties

```php
class License extends Model
{
    // Primary keys
    public string $id;           // ULID primary key
    public string $uid;          // ULID unique identifier for public use
    
    // Core properties
    public string $key_hash;              // SHA-256 hash of license key
    public LicenseStatus $status;         // Current license status
    public ?string $licensable_type;      // Polymorphic type
    public ?string $licensable_id;        // Polymorphic ID
    public ?string $template_id;          // Associated template
    public ?\DateTime $activated_at;      // Activation timestamp
    public ?\DateTime $expires_at;        // Expiration timestamp
    public int $max_usages;              // Maximum concurrent seats
    public ?array $meta;                 // Flexible metadata
    
    // Timestamps
    public \DateTime $created_at;
    public \DateTime $updated_at;
}
```

### Relationships

```php
// Polymorphic relationship to any model
public function licensable(): MorphTo

// Usage records (seats)
public function usages(): HasMany
public function activeUsages(): HasMany

// Renewal history
public function renewals(): HasMany

// Trial records
public function trials(): HasMany

// Transfer records
public function transfers(): HasMany
public function transferHistory(): HasMany

// Template
public function template(): BelongsTo
```

### Core Methods

#### Creation and Lookup

```php
// Create license from template
public static function createFromTemplate(
    string|LicenseTemplate $template, 
    array $attributes = []
): self

// Find by license key
public static function findByKey(string $key): ?self

// Find by UID
public static function findByUid(string $uid): ?self

// Hash license key
public static function hashKey(string $key): string

// Verify license key
public function verifyKey(string $key): bool
```

#### State Management

```php
// Activate license
public function activate(): self

// Renew license
public function renew(\DateTimeInterface $expiresAt, array $renewalData = []): self

// Suspend license
public function suspend(): self

// Cancel license
public function cancel(): self

// State transitions
public function transitionToGrace(): self
public function transitionToExpired(): self
```

#### Validation Methods

```php
// Check if license can be used
public function isUsable(): bool

// Check expiration
public function isExpired(): bool
public function isInGracePeriod(): bool
public function gracePeriodExpired(): bool
public function daysUntilExpiration(): ?int

// Seat availability
public function hasAvailableSeats(): bool
public function getAvailableSeats(): int
```

#### Policy and Configuration

```php
// Get policy values
public function getPolicy(string $key): mixed
public function getOverLimitPolicy(): OverLimitPolicy
public function getGraceDays(): int
public function getInactivityAutoRevokeDays(): ?int
public function getUniqueUsageScope(): string

// Offline token configuration
public function isOfflineTokenEnabled(): bool
public function getTokenFormat(): TokenFormat
public function getTokenTtlDays(): int
public function getForceOnlineAfterDays(): int
public function getClockSkewSeconds(): int
```

#### Feature and Entitlement Methods

```php
// Check features from template
public function hasFeature(string $feature): bool
public function getFeatures(): array

// Get entitlements from template
public function getEntitlement(string $key): mixed
public function getEntitlements(): array
```

#### Transfer Methods

```php
// Transfer management
public function hasPendingTransfers(): bool
public function getLatestTransfer(): ?LicenseTransfer
public function isTransferable(): bool
public function initiateTransfer(array $data): LicenseTransfer
```

### Scopes

```php
// Active licenses
License::active()->get();

// Expiring soon
License::expiringSoon(30)->get();

// By licensable
License::forLicensable($user)->get();
```

### Events

- `LicenseActivated` - When license is activated
- `LicenseRenewed` - When license is renewed
- `LicenseExpired` - When license expires
- `LicenseExpiringSoon` - When license is expiring soon

## LicenseUsage

Represents a consumed license seat/usage.

### Properties

```php
class LicenseUsage extends Model
{
    public string $id;                    // ULID primary key
    public string $license_id;            // Associated license
    public string $usage_fingerprint;     // Unique consumer identifier
    public UsageStatus $status;           // active, revoked, replaced
    public \DateTime $registered_at;      // First registration
    public ?\DateTime $last_seen_at;      // Last activity
    public ?\DateTime $revoked_at;        // Revocation timestamp
    public ?string $client_type;          // Type of client
    public ?string $name;                 // Human-readable name
    public ?string $ip;                   // IP address (optional)
    public ?string $user_agent;           // User agent (optional)
    public ?array $meta;                  // Additional metadata
}
```

### Relationships

```php
// Belongs to license
public function license(): BelongsTo
```

### Methods

```php
// Check if usage is active
public function isActive(): bool

// Revoke usage
public function revoke(): void

// Update activity timestamp
public function updateLastSeen(): void

// Get inactivity duration
public function getDaysInactive(): ?int
```

### Scopes

```php
// Active usages
LicenseUsage::active()->get();

// Inactive usages
LicenseUsage::inactive($days)->get();

// By client type
LicenseUsage::byClientType('desktop-app')->get();
```

## LicenseRenewal

Tracks license renewal periods and payments.

### Properties

```php
class LicenseRenewal extends Model
{
    public string $id;                    // ULID primary key
    public string $license_id;            // Associated license
    public \DateTime $period_start;       // Renewal period start
    public \DateTime $period_end;         // Renewal period end
    public ?int $amount_cents;            // Amount in cents
    public ?string $currency;             // Currency code
    public ?string $notes;                // Additional notes
}
```

### Relationships

```php
// Belongs to license
public function license(): BelongsTo
```

### Methods

```php
// Get renewal duration
public function getDurationInDays(): int

// Format amount with currency
public function getFormattedAmount(): ?string
```

### Scopes

```php
// Renewals in specific period
LicenseRenewal::inPeriod($date)->get();

// Upcoming renewals
LicenseRenewal::upcoming()->get();

// Past renewals
LicenseRenewal::past()->get();
```

## LicenseTemplate

Template for creating licenses with predefined configurations.

### Properties

```php
class LicenseTemplate extends Model
{
    public string $id;                         // ULID primary key
    public string $ulid;                       // Public ULID
    public string $group;                      // Template group
    public string $name;                       // Template name
    public string $slug;                       // URL-friendly identifier
    public int $tier_level;                    // Tier hierarchy level
    public ?string $parent_template_id;        // Parent template
    public ?array $base_configuration;        // Default config
    public ?array $features;                   // Available features
    public ?array $entitlements;              // Usage limits
    public bool $is_active;                    // Active status
    public ?array $meta;                       // Additional metadata
}
```

### Relationships

```php
// Parent/child hierarchy
public function parentTemplate(): BelongsTo
public function childTemplates(): HasMany

// Associated licenses
public function licenses(): HasMany
```

### Methods

```php
// Configuration resolution with inheritance
public function resolveConfiguration(): array
public function resolveFeatures(): array
public function resolveEntitlements(): array

// Feature checking
public function hasFeature(string $feature): bool
public function getEntitlement(string $key): mixed

// Tier comparison
public function isHigherTierThan(self $otherTemplate): bool
public function isLowerTierThan(self $otherTemplate): bool
public function isSameTierAs(self $otherTemplate): bool

// Lookup methods
public static function findBySlug(string $slug): ?self
public static function getForGroup(string $group): Collection
```

### Scopes

```php
// Active templates
LicenseTemplate::active()->get();

// By group
LicenseTemplate::byGroup('saas')->get();

// By tier level
LicenseTemplate::byTierLevel(2)->get();

// Ordered by tier
LicenseTemplate::orderedByTier()->get();
```

## LicenseTrial

Manages trial periods for licenses.

### Properties

```php
class LicenseTrial extends Model
{
    public string $id;                    // ULID primary key
    public string $license_id;            // Associated license
    public TrialStatus $status;           // active, converted, expired, cancelled
    public \DateTime $starts_at;          // Trial start
    public \DateTime $ends_at;            // Trial end
    public ?int $converted_at;            // Conversion timestamp
    public ?array $limitations;           // Trial limitations
    public ?array $feature_restrictions;  // Restricted features
    public ?array $meta;                  // Additional metadata
}
```

### Relationships

```php
// Belongs to license
public function license(): BelongsTo
```

### Methods

```php
// Trial state
public function isActive(): bool
public function isExpired(): bool
public function isConverted(): bool

// Actions
public function convert(): void
public function extend(\DateTime $newEndDate): void
public function cancel(): void

// Limitations
public function hasLimitation(string $key): bool
public function getLimitation(string $key): mixed
public function isFeatureRestricted(string $feature): bool
```

## LicenseTransfer

Manages license ownership transfers.

### Properties

```php
class LicenseTransfer extends Model
{
    public string $id;                    // ULID primary key
    public string $license_id;            // License being transferred
    public TransferType $type;            // ownership, temporary, delegation
    public TransferStatus $status;        // pending, approved, completed, rejected
    public string $initiator_type;       // Initiator polymorphic type
    public string $initiator_id;         // Initiator polymorphic ID
    public string $recipient_type;       // Recipient polymorphic type
    public string $recipient_id;         // Recipient polymorphic ID
    public ?string $reason;              // Transfer reason
    public ?\DateTime $effective_at;      // When transfer takes effect
    public ?\DateTime $expires_at;       // Transfer expiration
    public ?array $meta;                 // Additional data
}
```

### Relationships

```php
// Associated license
public function license(): BelongsTo

// Polymorphic relationships
public function initiator(): MorphTo
public function recipient(): MorphTo

// Approval records
public function approvals(): HasMany
```

### Methods

```php
// Transfer actions
public function approve(Model $approver, ?string $notes = null): void
public function reject(Model $approver, string $reason): void
public function complete(): void
public function cancel(string $reason): void

// Status checking
public function isPending(): bool
public function isApproved(): bool
public function isCompleted(): bool
public function isExpired(): bool

// Requirements
public function requiresApproval(): bool
public function canBeApproved(): bool
public function canBeCompleted(): bool
```

## LicenseTransferHistory

Historical record of completed transfers.

### Properties

```php
class LicenseTransferHistory extends Model
{
    public string $id;                    // ULID primary key
    public string $license_id;            // Associated license
    public string $transfer_id;           // Original transfer record
    public string $from_licensable_type;  // Previous owner type
    public string $from_licensable_id;    // Previous owner ID
    public string $to_licensable_type;    // New owner type
    public string $to_licensable_id;      // New owner ID
    public \DateTime $transferred_at;     // Transfer completion time
    public ?string $reason;               // Transfer reason
    public ?array $meta;                  // Transfer metadata
}
```

## LicenseTransferApproval

Approval records for transfer requests.

### Properties

```php
class LicenseTransferApproval extends Model
{
    public string $id;                    // ULID primary key
    public string $transfer_id;           // Associated transfer
    public string $approver_type;        // Approver polymorphic type
    public string $approver_id;           // Approver polymorphic ID
    public ApprovalStatus $status;        // approved, rejected
    public ?\DateTime $approved_at;       // Approval timestamp
    public ?string $notes;                // Approval notes
    public ?array $meta;                  // Additional data
}
```

## LicensingKey

Cryptographic keys for offline token signing.

### Properties

```php
class LicensingKey extends Model
{
    public string $id;                    // ULID primary key
    public string $kid;                   // Key identifier
    public KeyType $type;                 // root, signing
    public KeyStatus $status;             // active, revoked
    public ?string $public_key;           // Public key (PEM format)
    public ?string $private_key;          // Encrypted private key
    public ?\DateTime $not_before;        // Valid from
    public ?\DateTime $not_after;         // Valid until
    public ?\DateTime $revoked_at;        // Revocation time
    public ?string $issuer;               // Issuing key ID
    public ?array $meta;                  // Key metadata
}
```

### Methods

```php
// Key operations
public function isActive(): bool
public function isExpired(): bool
public function isRevoked(): bool
public function revoke(\DateTime $at = null): void

// Key material
public function getPublicKeyResource()
public function getPrivateKeyResource(string $passphrase = null)
public function canSign(): bool
public function canVerify(): bool
```

## LicensingAuditLog

Audit trail for licensing operations.

### Properties

```php
class LicensingAuditLog extends Model
{
    public string $id;                    // ULID primary key
    public AuditEventType $event_type;    // Type of event
    public ?string $entity_type;          // Affected entity type
    public ?string $entity_id;            // Affected entity ID
    public ?string $actor_type;           // Actor polymorphic type
    public ?string $actor_id;             // Actor polymorphic ID
    public array $data;                   // Event data
    public ?string $ip_address;           // Client IP
    public ?string $user_agent;           // Client user agent
    public ?string $hash_chain;           // Previous log hash (tamper-evidence)
    public \DateTime $created_at;         // Event timestamp
}
```

### Relationships

```php
// Polymorphic relationships
public function entity(): MorphTo
public function actor(): MorphTo
```

### Methods

```php
// Verification
public function verifyHashChain(): bool
public function calculateHash(): string

// Querying
public static function forEntity(Model $entity): Builder
public static function byEventType(AuditEventType $type): Builder
public static function byActor(Model $actor): Builder
```

## Common Patterns

### Model Configuration

All models can be customized via configuration:

```php
// config/licensing.php
return [
    'models' => [
        'license' => \App\Models\CustomLicense::class,
        'license_usage' => \App\Models\CustomLicenseUsage::class,
        // ... other models
    ],
];
```

### Extending Models

```php
// Custom license model
class CustomLicense extends License
{
    // Add custom methods
    public function getCustomAttribute(): string
    {
        return $this->meta['custom_field'] ?? '';
    }
    
    // Override behavior
    public function activate(): self
    {
        // Custom activation logic
        $this->sendActivationNotification();
        
        return parent::activate();
    }
    
    private function sendActivationNotification(): void
    {
        // Custom notification logic
    }
}
```

### Using Factories

All models include factories for testing:

```php
use LucaLongo\Licensing\Models\License;

// Create test license
$license = License::factory()->create();

// Create with specific attributes
$license = License::factory()->create([
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
]);

// Create with relationships
$license = License::factory()
    ->hasUsages(3)
    ->hasRenewals(1)
    ->create();
```

This API reference covers all models in the Laravel Licensing package. Each model provides a specific aspect of license management functionality with clear interfaces and relationships.