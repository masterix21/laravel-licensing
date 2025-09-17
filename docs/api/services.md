# API Reference: Services

This document provides comprehensive API reference for all services in the Laravel Licensing package. Services encapsulate business logic and provide high-level interfaces for licensing operations.

## Table of Contents

- [UsageRegistrarService](#usageregistrarservice)
- [PasetoTokenService](#pasetotokenservice)
- [CertificateAuthorityService](#certificateauthorityservice)
- [AuditLoggerService](#auditloggerservice)
- [FingerprintResolverService](#fingerprintresolverservice)
- [TrialService](#trialservice)
- [LicenseTransferService](#licensetransferservice)
- [TransferApprovalService](#transferapprovalservice)
- [TransferValidationService](#transfervalidationservice)
- [TemplateService](#templateservice)

## UsageRegistrarService

Manages license seat registration and usage tracking.

### Interface

```php
interface UsageRegistrar
{
    public function register(License $license, array $data): LicenseUsage;
    public function revoke(License $license, string $usageFingerprint): bool;
    public function heartbeat(License $license, string $usageFingerprint): bool;
}
```

### Implementation

```php
class UsageRegistrarService implements UsageRegistrar
{
    public function __construct(
        private FingerprintResolver $fingerprintResolver,
        private AuditLogger $auditLogger
    ) {}
}
```

### Methods

#### register()

Registers a new usage (consumes a seat).

```php
public function register(License $license, array $data): LicenseUsage

// Parameters:
// $license - License to register usage for
// $data - Usage data array with keys:
//   - usage_fingerprint (string, required): Unique identifier
//   - client_type (string, optional): Type of client
//   - name (string, optional): Human-readable name
//   - ip (string, optional): IP address
//   - user_agent (string, optional): User agent
//   - meta (array, optional): Additional metadata

// Returns: LicenseUsage instance

// Throws:
// - LicenseNotUsableException: License is not active
// - UsageLimitReachedException: No available seats
// - DuplicateUsageException: Fingerprint already registered
```

**Example:**

```php
$registrar = app(UsageRegistrarService::class);

$usage = $registrar->register($license, [
    'usage_fingerprint' => 'device-123-app-v2.1',
    'client_type' => 'desktop-app',
    'name' => 'John\'s MacBook Pro',
    'ip' => '192.168.1.100',
    'user_agent' => 'MyApp/2.1.0 (macOS/13.0)',
    'meta' => [
        'app_version' => '2.1.0',
        'os_version' => 'macOS 13.0',
    ]
]);
```

#### revoke()

Revokes an existing usage (frees a seat).

```php
public function revoke(License $license, string $usageFingerprint): bool

// Parameters:
// $license - License containing the usage
// $usageFingerprint - Fingerprint of usage to revoke

// Returns: true if revoked, false if not found
```

**Example:**

```php
$revoked = $registrar->revoke($license, 'device-123-app-v2.1');

if ($revoked) {
    echo "Usage revoked successfully";
} else {
    echo "Usage not found";
}
```

#### heartbeat()

Updates the last seen timestamp for a usage.

```php
public function heartbeat(License $license, string $usageFingerprint): bool

// Parameters:
// $license - License containing the usage
// $usageFingerprint - Fingerprint of usage to update

// Returns: true if updated, false if not found
```

**Example:**

```php
$updated = $registrar->heartbeat($license, 'device-123-app-v2.1');
```

### Configuration

The service respects license policies:

- **Over-limit policy**: `reject` or `auto_replace_oldest`
- **Unique usage scope**: `license` or `global`
- **Inactivity auto-revoke**: Number of days before auto-revocation

## PasetoTokenService

Handles offline token issuance and verification using PASETO v4.

### Interface

```php
interface TokenIssuer
{
    public function issue(License $license, string $usageFingerprint, array $claims = []): string;
}

interface TokenVerifier
{
    public function verify(string $token): array;
}
```

### Implementation

```php
class PasetoTokenService implements TokenIssuer, TokenVerifier
{
    public function __construct(
        private CertificateAuthority $ca,
        private AuditLogger $auditLogger
    ) {}
}
```

### Methods

#### issue()

Issues an offline verification token.

```php
public function issue(License $license, string $usageFingerprint, array $claims = []): string

// Parameters:
// $license - License to issue token for
// $usageFingerprint - Usage fingerprint for the token
// $claims - Additional claims to include

// Returns: PASETO v4 token string

// Throws:
// - LicenseNotUsableException: License is not active
// - TokenIssuanceException: Token generation failed
```

**Token Claims:**

```php
// Standard claims included automatically
$claims = [
    'iss' => 'laravel-licensing',           // Issuer
    'aud' => 'license-verification',        // Audience
    'iat' => time(),                        // Issued at
    'nbf' => time(),                        // Not before
    'exp' => time() + (7 * 24 * 3600),     // Expires (7 days)
    'license_id' => $license->id,           // License identifier
    'license_key_hash' => $license->key_hash, // License key hash
    'usage_fingerprint' => $fingerprint,    // Usage fingerprint
    'status' => $license->status->value,     // License status
    'max_usages' => $license->max_usages,    // Seat limit
    'features' => $license->getFeatures(),   // Available features
    'entitlements' => $license->getEntitlements(), // Usage limits
];

// Optional claims
if ($license->expires_at) {
    $claims['expires_at'] = $license->expires_at->getTimestamp();
}

if ($license->isInGracePeriod()) {
    $claims['grace_until'] = $license->expires_at
        ->addDays($license->getGraceDays())
        ->getTimestamp();
}
```

**Example:**

```php
$tokenService = app(PasetoTokenService::class);

$token = $tokenService->issue($license, 'device-123-app-v2.1', [
    'custom_claim' => 'custom_value',
    'client_version' => '2.1.0',
]);
```

#### verify()

Verifies and decodes a token.

```php
public function verify(string $token): array

// Parameters:
// $token - PASETO v4 token to verify

// Returns: Array of decoded claims

// Throws:
// - InvalidTokenException: Token is invalid or expired
// - TokenVerificationException: Verification failed
```

**Example:**

```php
try {
    $claims = $tokenService->verify($token);
    
    echo "License ID: " . $claims['license_id'];
    echo "Status: " . $claims['status'];
    echo "Features: " . json_encode($claims['features']);
    
} catch (InvalidTokenException $e) {
    echo "Token verification failed: " . $e->getMessage();
}
```

### Token Format

Tokens use PASETO v4 (public key) format with the following structure:

```
v4.public.{payload}.{footer}
```

**Header (Footer):**

```json
{
    "kid": "signing-key-id",
    "chain": "base64-encoded-certificate-chain",
    "version": "1.0"
}
```

## CertificateAuthorityService

Manages cryptographic keys and certificate chains for token signing.

### Interface

```php
interface CertificateAuthority
{
    public function generateRootKey(): string;
    public function issueSigningKey(string $kid, ?\DateTime $notBefore = null, ?\DateTime $notAfter = null): LicensingKey;
    public function revokeKey(string $kid, ?\DateTime $revokedAt = null): bool;
    public function rotateSigningKey(string $reason = 'routine'): LicensingKey;
    public function getActiveSigningKey(): ?LicensingKey;
    public function exportPublicKeys(string $format = 'json'): string;
}
```

### Methods

#### generateRootKey()

Creates a new root key pair for the certificate authority.

```php
public function generateRootKey(): string

// Returns: Key ID (kid) of the created root key

// Throws:
// - KeyGenerationException: Key generation failed
```

#### issueSigningKey()

Issues a new signing key signed by the root key.

```php
public function issueSigningKey(
    string $kid, 
    ?\DateTime $notBefore = null, 
    ?\DateTime $notAfter = null
): LicensingKey

// Parameters:
// $kid - Key identifier
// $notBefore - Valid from (defaults to now)
// $notAfter - Valid until (defaults to now + 90 days)

// Returns: LicensingKey instance
```

#### rotateSigningKey()

Revokes current signing key and issues a new one.

```php
public function rotateSigningKey(string $reason = 'routine'): LicensingKey

// Parameters:
// $reason - Reason for rotation (routine, compromised, etc.)

// Returns: New LicensingKey instance
```

## AuditLoggerService

Provides comprehensive audit logging for all licensing operations.

### Interface

```php
interface AuditLogger
{
    public function log(AuditEventType $eventType, ?Model $entity = null, array $data = [], ?Model $actor = null): void;
}
```

### Methods

#### log()

Records an audit log entry.

```php
public function log(
    AuditEventType $eventType, 
    ?Model $entity = null, 
    array $data = [], 
    ?Model $actor = null
): void

// Parameters:
// $eventType - Type of event being logged
// $entity - Entity affected by the event
// $data - Additional event data
// $actor - Who performed the action
```

**Example:**

```php
$auditLogger = app(AuditLoggerService::class);

$auditLogger->log(
    AuditEventType::LicenseActivated,
    $license,
    [
        'activation_method' => 'api',
        'client_ip' => request()->ip(),
    ],
    $user
);
```

### Event Types

```php
enum AuditEventType: string
{
    case LicenseCreated = 'license_created';
    case LicenseActivated = 'license_activated';
    case LicenseRenewed = 'license_renewed';
    case LicenseExpired = 'license_expired';
    case LicenseSuspended = 'license_suspended';
    case LicenseCancelled = 'license_cancelled';
    
    case UsageRegistered = 'usage_registered';
    case UsageRevoked = 'usage_revoked';
    case UsageReplaced = 'usage_replaced';
    
    case TrialStarted = 'trial_started';
    case TrialExtended = 'trial_extended';
    case TrialConverted = 'trial_converted';
    case TrialExpired = 'trial_expired';
    
    case TransferInitiated = 'transfer_initiated';
    case TransferApproved = 'transfer_approved';
    case TransferCompleted = 'transfer_completed';
    case TransferRejected = 'transfer_rejected';
    
    case KeyGenerated = 'key_generated';
    case KeyRotated = 'key_rotated';
    case KeyRevoked = 'key_revoked';
    
    case TokenIssued = 'token_issued';
    case TokenVerified = 'token_verified';
}
```

## FingerprintResolverService

Generates stable fingerprints for usage identification.

### Interface

```php
interface FingerprintResolver
{
    public function resolve(array $context = []): string;
}
```

### Methods

#### resolve()

Generates a fingerprint from context data.

```php
public function resolve(array $context = []): string

// Parameters:
// $context - Array of fingerprint components

// Returns: SHA-256 hash fingerprint
```

**Example:**

```php
$resolver = app(FingerprintResolverService::class);

$fingerprint = $resolver->resolve([
    'hardware_id' => 'MAC-ABCD1234',
    'installation_id' => 'INST-XYZ789',
    'app_version' => '2.1.0',
    'device_id' => 'device-unique-id',
]);
```

## TrialService

Manages trial periods for licenses.

### Methods

#### startTrial()

Initiates a trial period for a license.

```php
public function startTrial(
    License $license, 
    int $durationDays = null, 
    array $limitations = [], 
    array $featureRestrictions = []
): LicenseTrial

// Parameters:
// $license - License to start trial for
// $durationDays - Trial duration (uses config default if null)
// $limitations - Usage limitations during trial
// $featureRestrictions - Features to restrict during trial

// Returns: LicenseTrial instance
```

#### extendTrial()

Extends an existing trial period.

```php
public function extendTrial(LicenseTrial $trial, int $additionalDays): LicenseTrial

// Parameters:
// $trial - Trial to extend
// $additionalDays - Additional days to add

// Returns: Updated LicenseTrial instance
```

#### convertTrial()

Converts a trial to a full license.

```php
public function convertTrial(LicenseTrial $trial): License

// Parameters:
// $trial - Trial to convert

// Returns: Updated License instance
```

## LicenseTransferService

Handles license ownership transfers.

### Methods

#### initiateTransfer()

Starts a license transfer process.

```php
public function initiateTransfer(License $license, array $data): LicenseTransfer

// Parameters:
// $license - License to transfer
// $data - Transfer data including recipient information

// Returns: LicenseTransfer instance
```

#### completeTransfer()

Finalizes a license transfer.

```php
public function completeTransfer(LicenseTransfer $transfer): License

// Parameters:
// $transfer - Transfer to complete

// Returns: Updated License instance
```

## TransferApprovalService

Manages transfer approval workflows.

### Methods

#### requiresApproval()

Checks if a transfer requires approval.

```php
public function requiresApproval(LicenseTransfer $transfer): bool

// Parameters:
// $transfer - Transfer to check

// Returns: true if approval required
```

#### approve()

Approves a transfer request.

```php
public function approve(LicenseTransfer $transfer, Model $approver, ?string $notes = null): LicenseTransferApproval

// Parameters:
// $transfer - Transfer to approve
// $approver - Who is approving
// $notes - Optional approval notes

// Returns: LicenseTransferApproval instance
```

## TransferValidationService

Validates transfer requests and requirements.

### Methods

#### validateTransfer()

Validates a transfer request.

```php
public function validateTransfer(LicenseTransfer $transfer): array

// Parameters:
// $transfer - Transfer to validate

// Returns: Array with validation results
// [
//     'valid' => bool,
//     'errors' => array,
//     'warnings' => array
// ]
```

## TemplateService

Coordinates scope-aware template management and license provisioning.

### Common Responsibilities
- Retrieve templates for a specific scope or global catalog
- Assign or detach templates from product scopes
- Provision licenses from templates while enforcing scope membership
- Resolve configuration, features, and entitlements with inheritance
- Seed default plan hierarchies and handle tier upgrades

### Methods

#### getTemplatesForScope()

```php
public function getTemplatesForScope(?LicenseScope $scope = null, bool $onlyActive = true): Collection
```

Returns ordered templates for the given scope. Pass `null` to retrieve global templates. When `$onlyActive` is `true` (default) the result excludes inactive plans.

#### createLicenseFromTemplate()

```php
public function createLicenseFromTemplate(string|LicenseTemplate $template, array $attributes = []): License
```

Convenience wrapper around `License::createFromTemplate()` that accepts either a template instance or slug. Scope linkage on the template is respected automatically.

#### createLicenseForScope()

```php
public function createLicenseForScope(LicenseScope $scope, string|LicenseTemplate $template, array $attributes = []): License
```

Validates that the template belongs to the provided scope (or is global) and provisions a license with `license_scope_id` enforced.

#### assignTemplateToScope()

```php
public function assignTemplateToScope(LicenseScope $scope, LicenseTemplate $template): LicenseTemplate
```

Pins a template to the scope. Throws an `InvalidArgumentException` if the template is already attached to a different scope.

#### removeTemplateFromScope()

```php
public function removeTemplateFromScope(LicenseScope $scope, LicenseTemplate|int|string $template): bool
```

Detaches a template from the scope by nulling `license_scope_id`. Returns `false` if the template is not associated with the scope.

#### resolveConfiguration() / resolveFeatures() / resolveEntitlements()

```php
public function resolveConfiguration(LicenseTemplate $template): array
public function resolveFeatures(LicenseTemplate $template): array
public function resolveEntitlements(LicenseTemplate $template): array
```

Bubble configuration, feature flags, and entitlements through the inheritance tree and return the merged arrays.

#### seedDefaultTemplates()

```php
public function seedDefaultTemplates(?LicenseScope $scope = null): Collection
```

Creates or updates a Basic/Pro/Enterprise tier set for the given scope (or globally when `null`). Returns the created templates in tier order.

#### upgradeLicense()

```php
public function upgradeLicense(License $license, string|LicenseTemplate $newTemplate): License
```

Moves a license to a higher tier, copying new configuration values. Throws if attempting to downgrade or if the template is not found.

#### getAvailableUpgrades()

```php
public function getAvailableUpgrades(License $license): Collection
```

Returns templates with greater `tier_level` in the same scope (or all active templates when the license has no template).

## Service Configuration

Services are bound in the service container and can be customized:

```php
// config/licensing.php
return [
    'services' => [
        'usage_registrar' => UsageRegistrarService::class,
        'token_service' => PasetoTokenService::class,
        'certificate_authority' => CertificateAuthorityService::class,
        'audit_logger' => AuditLoggerService::class,
        'fingerprint_resolver' => FingerprintResolverService::class,
        // ... other services
    ],
];
```

## Custom Service Implementation

You can replace any service with custom implementations:

```php
// Custom usage registrar
class CustomUsageRegistrar implements UsageRegistrar
{
    public function register(License $license, array $data): LicenseUsage
    {
        // Custom registration logic
    }
    
    // ... implement other methods
}

// Register in service provider
app()->bind(UsageRegistrar::class, CustomUsageRegistrar::class);
```

This comprehensive service API reference provides the foundation for all licensing operations. Services encapsulate complex business logic while maintaining clean, testable interfaces.