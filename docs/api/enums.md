# API Reference: Enums

This document provides comprehensive API reference for all enums in the Laravel Licensing package. Enums provide type-safe constants and business logic for various states and options.

## Table of Contents

- [Core Enums](#core-enums)
- [Status Enums](#status-enums)
- [Configuration Enums](#configuration-enums)
- [Using Enums](#using-enums)

## Core Enums

### LicenseStatus

Defines the possible states of a license throughout its lifecycle.

```php
namespace LucaLongo\Licensing\Enums;

enum LicenseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
```

**States:**

- **`Pending`** - License created but not yet activated
- **`Active`** - License is activated and usable
- **`Grace`** - License expired but still usable within grace period
- **`Expired`** - License expired and no longer usable
- **`Suspended`** - License temporarily disabled
- **`Cancelled`** - License permanently disabled

**Methods:**

```php
// Check if license can be activated
public function canActivate(): bool
{
    return $this === self::Pending;
}

// Check if license can be renewed
public function canRenew(): bool
{
    return in_array($this, [
        self::Active,
        self::Grace, 
        self::Expired
    ]);
}

// Check if license is usable
public function isUsable(): bool
{
    return in_array($this, [self::Active, self::Grace]);
}

// Get available transitions
public function availableTransitions(): array
{
    return match($this) {
        self::Pending => [self::Active, self::Cancelled],
        self::Active => [self::Grace, self::Suspended, self::Cancelled],
        self::Grace => [self::Active, self::Expired, self::Cancelled],
        self::Expired => [self::Active, self::Cancelled],
        self::Suspended => [self::Active, self::Cancelled],
        self::Cancelled => [],
    };
}
```

**Usage Examples:**

```php
$license = License::find(1);

// Check current status
if ($license->status === LicenseStatus::Active) {
    echo "License is active";
}

// Validate transitions
if ($license->status->canActivate()) {
    $license->activate();
}

// Filter queries
License::where('status', LicenseStatus::Active)->get();

// Get human-readable status
echo $license->status->value; // "active"
```

### UsageStatus

Defines the possible states of license usage records.

```php
namespace LucaLongo\Licensing\Enums;

enum UsageStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Replaced = 'replaced';
}
```

**States:**

- **`Active`** - Usage is consuming a seat
- **`Revoked`** - Usage manually revoked, seat freed
- **`Replaced`** - Usage auto-replaced by over-limit policy

**Methods:**

```php
// Check if usage is consuming a seat
public function isConsumingSeat(): bool
{
    return $this === self::Active;
}

// Check if usage was terminated
public function isTerminated(): bool
{
    return in_array($this, [self::Revoked, self::Replaced]);
}
```

### TrialStatus

Defines the possible states of trial periods.

```php
namespace LucaLongo\Licensing\Enums;

enum TrialStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
```

**States:**

- **`Active`** - Trial is ongoing
- **`Converted`** - Trial converted to full license
- **`Expired`** - Trial period ended without conversion
- **`Cancelled`** - Trial was cancelled

### TransferStatus

Defines the possible states of license transfers.

```php
namespace LucaLongo\Licensing\Enums;

enum TransferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
```

**States:**

- **`Pending`** - Transfer initiated, awaiting approval
- **`Approved`** - Transfer approved, ready to complete
- **`Completed`** - Transfer successfully completed
- **`Rejected`** - Transfer rejected by approver
- **`Cancelled`** - Transfer cancelled by initiator
- **`Expired`** - Transfer request expired

### ApprovalStatus

Defines approval states for transfer requests.

```php
namespace LucaLongo\Licensing\Enums;

enum ApprovalStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

## Status Enums

### KeyStatus

Defines the status of cryptographic keys.

```php
namespace LucaLongo\Licensing\Enums;

enum KeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
```

**Methods:**

```php
// Check if key can be used for signing
public function canSign(): bool
{
    return $this === self::Active;
}

// Check if key can be used for verification
public function canVerify(): bool
{
    return in_array($this, [self::Active, self::Expired]);
}
```

### KeyType

Defines types of cryptographic keys.

```php
namespace LucaLongo\Licensing\Enums;

enum KeyType: string
{
    case Root = 'root';
    case Signing = 'signing';
}
```

**Methods:**

```php
// Check if key is a root key
public function isRoot(): bool
{
    return $this === self::Root;
}

// Check if key is a signing key
public function isSigning(): bool
{
    return $this === self::Signing;
}
```

## Configuration Enums

### OverLimitPolicy

Defines behavior when usage limits are exceeded.

```php
namespace LucaLongo\Licensing\Enums;

enum OverLimitPolicy: string
{
    case Reject = 'reject';
    case AutoReplaceOldest = 'auto_replace_oldest';
}
```

**Methods:**

```php
// Check if policy allows over-limit registration
public function allowsOverLimit(): bool
{
    return $this === self::AutoReplaceOldest;
}

// Get description of policy behavior
public function getDescription(): string
{
    return match($this) {
        self::Reject => 'Reject new usage when limit reached',
        self::AutoReplaceOldest => 'Replace oldest usage when limit reached',
    };
}
```

### TokenFormat

Defines supported offline token formats.

```php
namespace LucaLongo\Licensing\Enums;

enum TokenFormat: string
{
    case Paseto = 'paseto';
    case Jws = 'jws';
}
```

**Methods:**

```php
// Get MIME type for token format
public function getMimeType(): string
{
    return match($this) {
        self::Paseto => 'application/paseto',
        self::Jws => 'application/jose+json',
    };
}

// Check if format supports public key verification
public function supportsPublicKey(): bool
{
    return true; // Both formats support public key verification
}
```

### TransferType

Defines types of license transfers.

```php
namespace LucaLongo\Licensing\Enums;

enum TransferType: string
{
    case Ownership = 'ownership';
    case Temporary = 'temporary';
    case Delegation = 'delegation';
}
```

**Methods:**

```php
// Check if transfer changes ownership permanently
public function isPermanent(): bool
{
    return $this === self::Ownership;
}

// Check if transfer is reversible
public function isReversible(): bool
{
    return in_array($this, [self::Temporary, self::Delegation]);
}

// Get required approval level
public function getApprovalLevel(): string
{
    return match($this) {
        self::Ownership => 'admin',
        self::Temporary => 'manager',
        self::Delegation => 'user',
    };
}
```

### AuditEventType

Defines types of events for audit logging.

```php
namespace LucaLongo\Licensing\Enums;

enum AuditEventType: string
{
    // License events
    case LicenseCreated = 'license_created';
    case LicenseActivated = 'license_activated';
    case LicenseRenewed = 'license_renewed';
    case LicenseExpired = 'license_expired';
    case LicenseSuspended = 'license_suspended';
    case LicenseCancelled = 'license_cancelled';
    
    // Usage events
    case UsageRegistered = 'usage_registered';
    case UsageRevoked = 'usage_revoked';
    case UsageReplaced = 'usage_replaced';
    
    // Trial events
    case TrialStarted = 'trial_started';
    case TrialExtended = 'trial_extended';
    case TrialConverted = 'trial_converted';
    case TrialExpired = 'trial_expired';
    
    // Transfer events
    case TransferInitiated = 'transfer_initiated';
    case TransferApproved = 'transfer_approved';
    case TransferCompleted = 'transfer_completed';
    case TransferRejected = 'transfer_rejected';
    
    // Key events
    case KeyGenerated = 'key_generated';
    case KeyRotated = 'key_rotated';
    case KeyRevoked = 'key_revoked';
    
    // Token events
    case TokenIssued = 'token_issued';
    case TokenVerified = 'token_verified';
}
```

**Methods:**

```php
// Get event category
public function getCategory(): string
{
    return match($this) {
        self::LicenseCreated, self::LicenseActivated, 
        self::LicenseRenewed, self::LicenseExpired,
        self::LicenseSuspended, self::LicenseCancelled => 'license',
        
        self::UsageRegistered, self::UsageRevoked, 
        self::UsageReplaced => 'usage',
        
        self::TrialStarted, self::TrialExtended,
        self::TrialConverted, self::TrialExpired => 'trial',
        
        self::TransferInitiated, self::TransferApproved,
        self::TransferCompleted, self::TransferRejected => 'transfer',
        
        self::KeyGenerated, self::KeyRotated, 
        self::KeyRevoked => 'key',
        
        self::TokenIssued, self::TokenVerified => 'token',
    };
}

// Check if event requires sensitive data handling
public function isSensitive(): bool
{
    return in_array($this, [
        self::KeyGenerated,
        self::KeyRotated,
        self::TokenIssued,
    ]);
}

// Get severity level
public function getSeverity(): string
{
    return match($this) {
        self::LicenseCreated, self::UsageRegistered,
        self::TrialStarted, self::TokenVerified => 'info',
        
        self::LicenseActivated, self::LicenseRenewed,
        self::TrialConverted, self::TransferCompleted => 'notice',
        
        self::LicenseExpired, self::UsageRevoked,
        self::TrialExpired, self::TransferRejected => 'warning',
        
        self::LicenseSuspended, self::LicenseCancelled,
        self::KeyRevoked => 'alert',
        
        self::KeyGenerated, self::KeyRotated => 'critical',
    };
}
```

## Using Enums

### In Models

```php
class License extends Model
{
    protected $casts = [
        'status' => LicenseStatus::class,
    ];
}

// Usage
$license = License::find(1);
$license->status = LicenseStatus::Active;
```

### In Validation

```php
use Illuminate\Validation\Rules\Enum;

$request->validate([
    'status' => [new Enum(LicenseStatus::class)],
    'over_limit_policy' => [new Enum(OverLimitPolicy::class)],
]);
```

### In Database Queries

```php
// Find active licenses
License::where('status', LicenseStatus::Active)->get();

// Find by multiple statuses
License::whereIn('status', [
    LicenseStatus::Active,
    LicenseStatus::Grace
])->get();

// Scope using enums
License::where('status', '!=', LicenseStatus::Cancelled)->get();
```

### In API Responses

```php
return response()->json([
    'license' => [
        'id' => $license->id,
        'status' => $license->status->value,
        'can_activate' => $license->status->canActivate(),
        'is_usable' => $license->status->isUsable(),
    ]
]);
```

### Custom Enum Methods

You can extend enums with custom methods:

```php
enum LicenseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    // ... other cases
    
    // Custom method for UI display
    public function getDisplayName(): string
    {
        return match($this) {
            self::Pending => 'Waiting for Activation',
            self::Active => 'Currently Active',
            self::Grace => 'Grace Period',
            self::Expired => 'Expired',
            self::Suspended => 'Temporarily Suspended',
            self::Cancelled => 'Cancelled',
        };
    }
    
    // Custom method for CSS classes
    public function getCssClass(): string
    {
        return match($this) {
            self::Pending => 'status-pending',
            self::Active => 'status-active',
            self::Grace => 'status-warning',
            self::Expired => 'status-expired',
            self::Suspended, self::Cancelled => 'status-disabled',
        };
    }
    
    // Custom method for permissions
    public function allowsUsageRegistration(): bool
    {
        return in_array($this, [self::Active, self::Grace]);
    }
}
```

### Enum Collections

Work with collections of enum values:

```php
// Get all active statuses
$activeStatuses = collect([
    LicenseStatus::Active,
    LicenseStatus::Grace,
]);

// Filter licenses by active statuses
License::whereIn('status', $activeStatuses->map(fn($status) => $status->value))->get();

// Get enum statistics
$statusCounts = License::selectRaw('status, COUNT(*) as count')
    ->groupBy('status')
    ->get()
    ->mapWithKeys(fn($row) => [
        LicenseStatus::from($row->status)->getDisplayName() => $row->count
    ]);
```

### Testing with Enums

```php
class LicenseTest extends TestCase
{
    public function test_license_can_be_activated()
    {
        $license = License::factory()->create(['status' => LicenseStatus::Pending]);
        
        $this->assertTrue($license->status->canActivate());
        
        $license->activate();
        
        $this->assertEquals(LicenseStatus::Active, $license->status);
        $this->assertTrue($license->status->isUsable());
    }
    
    public function test_expired_license_cannot_register_usage()
    {
        $license = License::factory()->create(['status' => LicenseStatus::Expired]);
        
        $this->assertFalse($license->status->allowsUsageRegistration());
    }
}
```

Enums provide type safety, better IDE support, and encapsulate business logic related to state management in the Laravel Licensing package. They make the codebase more maintainable and reduce errors from invalid state values.