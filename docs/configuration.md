# ⚙️ Configuration Guide

Comprehensive guide to configuring Laravel Licensing for your application.

## Configuration File

The main configuration file is published to `config/licensing.php`. This file controls all aspects of the licensing system.

## Model Configuration

### Custom Models

Override default models with your own implementations:

```php
'models' => [
    'license' => \App\Models\License::class,
    'license_usage' => \App\Models\LicenseUsage::class,
    'license_renewal' => \App\Models\LicenseRenewal::class,
    'license_trial' => \App\Models\LicenseTrial::class,
    'license_template' => \App\Models\LicenseTemplate::class,
    'license_transfer' => \App\Models\LicenseTransfer::class,
    'license_transfer_approval' => \App\Models\LicenseTransferApproval::class,
    'license_transfer_history' => \App\Models\LicenseTransferHistory::class,
    'licensing_key' => \App\Models\LicensingKey::class,
    'audit_log' => \App\Models\LicensingAuditLog::class,
],

'services' => [
    'key_generator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyGenerator::class,
    'key_retriever' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRetriever::class,
    'key_regenerator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRegenerator::class,
],
```

### Service Configuration

Configure license key management services:

```php
'services' => [
    'key_generator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyGenerator::class,
    'key_retriever' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRetriever::class,
    'key_regenerator' => \LucaLongo\Licensing\Services\EncryptedLicenseKeyRegenerator::class,
],

'key_management' => [
    // Enable/disable key retrieval
    'retrieval_enabled' => true,

    // Enable/disable key regeneration
    'regeneration_enabled' => true,

    // Key format settings
    'key_prefix' => 'LIC',        // Prefix for generated keys
    'key_separator' => '-',       // Separator for key segments
],
```

### Custom Key Services

Implement your own key generation logic:

```php
namespace App\Services;

use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Models\License;

class CustomLicenseKeyGenerator implements LicenseKeyGeneratorContract
{
    public function generate(?License $license = null): string
    {
        // Custom logic based on license context
        $prefix = 'CUSTOM';
        $year = date('Y');
        $random = strtoupper(bin2hex(random_bytes(4)));

        // Include license tier in key
        if ($license?->template) {
            $prefix = match($license->template->tier_level) {
                1 => 'BASIC',
                2 => 'PRO',
                3 => 'ENTERPRISE',
                default => 'CUSTOM'
            };
        }

        return "{$prefix}-{$year}-{$random}";
    }
}
```

Register your custom services in a service provider:

```php
// In AppServiceProvider or custom provider
public function register()
{
    $this->app->bind(
        \LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract::class,
        \App\Services\CustomLicenseKeyGenerator::class
    );
}
```

### Security Configuration

Configure key security settings:

```php
'key_management' => [
    // Disable key retrieval for maximum security
    'retrieval_enabled' => false,

    // Allow regeneration for support scenarios
    'regeneration_enabled' => true,

    // Custom key validation
    'validation_pattern' => '/^[A-Z]{3}-\d{4}-[A-Z0-9]{8}$/',

    // Audit previous keys
    'audit_regeneration' => true,

    // Maximum regenerations per license
    'max_regenerations' => 5,
],
```

### Creating Custom Models

Your custom models must extend the package models:

```php
namespace App\Models;

use LucaLongo\Licensing\Models\License as BaseLicense;

class License extends BaseLicense
{
    // Add custom relationships
    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
    
    // Add custom methods
    public function getRemainingValue(): float
    {
        // Custom business logic
        return $this->meta['prepaid_balance'] ?? 0;
    }
    
    // Override existing methods
    public function activate(): self
    {
        // Custom activation logic
        $this->notifyAdmins();
        
        return parent::activate();
    }
}
```

### Polymorphic Relationships

Define which models can own licenses:

```php
'morph_map' => [
    'user' => \App\Models\User::class,
    'team' => \App\Models\Team::class,
    'organization' => \App\Models\Organization::class,
    'device' => \App\Models\Device::class,
],
```

Add the trait to licensable models:

```php
namespace App\Models;

use LucaLongo\Licensing\Traits\HasLicenses;

class Organization extends Model
{
    use HasLicenses;
    
    // Access licenses
    public function activeLicense()
    {
        return $this->licenses()
            ->where('status', 'active')
            ->latest()
            ->first();
    }
}
```

## Policy Configuration

### Usage Policies

Configure how the system handles various scenarios:

```php
'policies' => [
    // How to handle when usage limit is reached
    'over_limit' => 'reject', // Options: 'reject', 'auto_replace_oldest'
    
    // Grace period after expiration (days)
    'grace_days' => 14,
    
    // Auto-revoke inactive usages after X days (null to disable)
    'usage_inactivity_auto_revoke_days' => 90,
    
    // Scope for usage uniqueness
    'unique_usage_scope' => 'license', // Options: 'license', 'global'
    
    // Allow multiple trials per entity
    'allow_multiple_trials' => false,
    
    // Maximum trial extensions allowed
    'max_trial_extensions' => 1,
    
    // Days before expiration to send warning
    'expiration_warning_days' => [30, 14, 7, 1],
],
```

### Over-Limit Policies Explained

#### `reject` Policy
Rejects new usage registration when limit is reached:

```php
'over_limit' => 'reject',

// Result when limit reached:
// UsageLimitReachedException thrown
// User must manually remove a device
```

#### `auto_replace_oldest` Policy
Automatically revokes the least recently used device:

```php
'over_limit' => 'auto_replace_oldest',

// Result when limit reached:
// Oldest device is automatically revoked
// New device is registered
// User is notified of the change
```

### Custom Policy Implementation

Create custom policy handlers:

```php
namespace App\Licensing\Policies;

use LucaLongo\Licensing\Contracts\OverLimitHandler;

class CustomOverLimitPolicy implements OverLimitHandler
{
    public function handle(License $license, string $fingerprint): LicenseUsage
    {
        // Custom logic for handling over-limit
        // For example: queue for admin approval
        
        dispatch(new RequestUsageApproval($license, $fingerprint));
        
        throw new PendingApprovalException(
            'Your device registration is pending approval.'
        );
    }
}

// Register in service provider
$this->app->bind(OverLimitHandler::class, CustomOverLimitPolicy::class);
```

## Offline Token Configuration

### Token Settings

Configure offline token generation and validation:

```php
'offline_token' => [
    // Enable/disable offline tokens
    'enabled' => true,
    
    // Token format
    'format' => 'paseto', // Options: 'paseto', 'jws'
    
    // Token time-to-live (days)
    'ttl_days' => 7,
    
    // Force online validation after X days
    'force_online_after_days' => 14,
    
    // Clock skew tolerance (seconds)
    'clock_skew_seconds' => 60,
    
    // Include full entitlements in token
    'include_entitlements' => true,
    
    // Include feature flags in token
    'include_features' => true,
    
    // Custom claims to include
    'custom_claims' => [
        'organization_name',
        'support_level',
    ],
],
```

### Token Format Comparison

#### PASETO (Recommended)
```php
'format' => 'paseto',
```
- ✅ Purpose-built for tokens
- ✅ Simpler, more secure by default
- ✅ No algorithm confusion attacks
- ✅ Smaller token size

#### JWS (JWT Compatible)
```php
'format' => 'jws',
```
- ✅ Wide library support
- ✅ Industry standard
- ⚠️ Requires careful configuration
- ⚠️ Larger token size

## Cryptographic Configuration

### Key Storage

Configure how cryptographic keys are stored:

```php
'crypto' => [
    // Signing algorithm
    'algorithm' => 'ed25519', // Options: 'ed25519', 'ES256'
    
    // Key storage configuration
    'keystore' => [
        // Storage driver
        'driver' => 'database', // Options: 'database', 'files', 'custom'
        
        // File storage path (if using files driver)
        'path' => storage_path('app/licensing/keys'),
        
        // Environment variable for passphrase
        'passphrase_env' => 'LICENSING_KEY_PASSPHRASE',
        
        // Key rotation settings
        'auto_rotate' => true,
        'rotation_days' => 30,
        
        // Backup settings
        'backup_enabled' => true,
        'backup_path' => storage_path('app/licensing/backups'),
    ],
],
```

### Custom Key Storage Driver

Implement custom key storage:

```php
namespace App\Licensing\KeyStores;

use LucaLongo\Licensing\Contracts\KeyStore;

class VaultKeyStore implements KeyStore
{
    public function store(string $kid, array $keyData): void
    {
        // Store in HashiCorp Vault
        $this->vault->write("secret/licensing/keys/{$kid}", $keyData);
    }
    
    public function retrieve(string $kid): ?array
    {
        return $this->vault->read("secret/licensing/keys/{$kid}");
    }
    
    public function delete(string $kid): void
    {
        $this->vault->delete("secret/licensing/keys/{$kid}");
    }
    
    public function list(): array
    {
        return $this->vault->list("secret/licensing/keys");
    }
}

// Register in service provider
$this->app->bind(KeyStore::class, VaultKeyStore::class);
```

## Publishing Configuration

### Public Key Distribution

Configure how public keys are distributed:

```php
'publishing' => [
    // JWKS endpoint URL (for JWS tokens)
    'jwks_url' => env('APP_URL') . '/api/licensing/v1/.well-known/jwks.json',
    
    // Public key bundle path
    'public_bundle_path' => storage_path('app/licensing/public-bundle.json'),
    
    // Cache duration for public keys (seconds)
    'cache_ttl' => 3600,
    
    // Include certificate chain
    'include_chain' => true,
    
    // Allowed CORS origins for JWKS
    'cors_origins' => [
        'https://app.example.com',
        'https://desktop.example.com',
    ],
],
```

## Rate Limiting

### API Rate Limits

Configure rate limiting for API endpoints:

```php
'rate_limit' => [
    // Validation endpoint (per minute per license)
    'validate_per_minute' => 60,
    
    // Token issuance (per minute per license)
    'token_per_minute' => 20,
    
    // Usage registration (per minute per license)
    'register_per_minute' => 30,
    
    // Heartbeat updates (per minute per usage)
    'heartbeat_per_minute' => 120,
    
    // Admin endpoints (per minute per user)
    'admin_per_minute' => 100,
    
    // Global rate limit (per minute per IP)
    'global_per_minute' => 1000,
],
```

### Custom Rate Limiting

Implement custom rate limiters:

```php
namespace App\Licensing\RateLimiters;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class LicenseRateLimiter
{
    public function define()
    {
        RateLimiter::for('licensing-api', function (Request $request) {
            $license = $this->getLicenseFromRequest($request);
            
            if ($license?->isEnterprise()) {
                return Limit::none(); // No limit for enterprise
            }
            
            if ($license?->isPro()) {
                return Limit::perMinute(200)->by($license->id);
            }
            
            return Limit::perMinute(60)->by(
                $request->input('license_key') ?: $request->ip()
            );
        });
    }
}
```

## Notification Configuration

### Notification Channels

Configure how notifications are sent:

```php
'notifications' => [
    // Notification channels
    'channels' => [
        'mail',
        'database',
        'slack',
        'webhook',
    ],
    
    // Events to notify
    'events' => [
        'license_created' => ['mail'],
        'license_activated' => ['mail', 'webhook'],
        'license_expiring_soon' => ['mail', 'database'],
        'license_expired' => ['mail', 'slack'],
        'license_renewed' => ['mail'],
        'usage_limit_reached' => ['mail', 'database'],
        'transfer_initiated' => ['mail'],
        'transfer_completed' => ['mail', 'webhook'],
        'trial_expiring' => ['mail'],
        'key_rotated' => ['slack'],
    ],
    
    // Webhook configuration
    'webhook' => [
        'url' => env('LICENSING_WEBHOOK_URL'),
        'secret' => env('LICENSING_WEBHOOK_SECRET'),
        'timeout' => 30,
        'retry' => 3,
    ],
    
    // Slack configuration
    'slack' => [
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
        'channel' => '#licensing',
        'username' => 'License Bot',
    ],
],
```

### Custom Notification Implementation

Create custom notifications:

```php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use LucaLongo\Licensing\Models\License;

class LicenseExpiringNotification extends Notification
{
    use Queueable;
    
    public function __construct(
        private License $license,
        private int $daysRemaining
    ) {}
    
    public function via($notifiable)
    {
        return config('licensing.notifications.channels');
    }
    
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject("License Expiring in {$this->daysRemaining} days")
            ->line("Your license will expire on {$this->license->expires_at->format('F j, Y')}")
            ->action('Renew Now', url('/billing/renew'))
            ->line('Thank you for your business!');
    }
    
    public function toSlack($notifiable)
    {
        return (new SlackMessage)
            ->warning()
            ->content("License {$this->license->uid} expiring in {$this->daysRemaining} days")
            ->attachment(function ($attachment) {
                $attachment->fields([
                    'Customer' => $this->license->licensable->name,
                    'Plan' => $this->license->template->name,
                    'Expires' => $this->license->expires_at->toDateString(),
                ]);
            });
    }
}
```

## Audit Configuration

### Audit Logging

Configure audit trail settings:

```php
'audit' => [
    // Enable/disable audit logging
    'enabled' => true,
    
    // Storage driver
    'store' => 'database', // Options: 'database', 'file', 'custom'
    
    // Retention period (days)
    'retention_days' => 90,
    
    // Events to audit
    'events' => [
        // License events
        'license.*' => true,
        
        // Usage events
        'usage.*' => true,
        
        // Key management events
        'key.*' => true,
        
        // API access
        'api.unauthorized' => true,
        'api.rate_limit_exceeded' => true,
        
        // Admin actions
        'admin.*' => true,
    ],
    
    // Include request data
    'include_request' => true,
    
    // Include response data
    'include_response' => false,
    
    // Anonymize PII
    'anonymize_pii' => true,
    
    // Hash chain for integrity
    'enable_hash_chain' => true,
],
```

### Custom Audit Logger

Implement custom audit logging:

```php
namespace App\Licensing\Audit;

use LucaLongo\Licensing\Contracts\AuditLogger;

class ElasticsearchAuditLogger implements AuditLogger
{
    public function log(
        AuditEventType $eventType,
        array $data,
        ?string $actor = null,
        array $context = []
    ): void {
        $this->elasticsearch->index([
            'index' => 'licensing-audit',
            'body' => [
                'event_type' => $eventType->value,
                'data' => $data,
                'actor' => $actor ?? $this->resolveActor(),
                'context' => $context,
                'timestamp' => now()->toIso8601String(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ]);
    }
    
    public function query(array $filters = []): iterable
    {
        return $this->elasticsearch->search([
            'index' => 'licensing-audit',
            'body' => $this->buildQuery($filters),
        ]);
    }
}
```

## Environment-Specific Configuration

### Development Environment

```php
// config/licensing.development.php
return [
    'policies' => [
        'grace_days' => 30, // Longer grace period for testing
    ],
    'offline_token' => [
        'ttl_days' => 1, // Shorter TTL for testing
    ],
    'rate_limit' => [
        'validate_per_minute' => 1000, // Higher limits for testing
    ],
    'audit' => [
        'enabled' => false, // Disable in development
    ],
];
```

### Production Environment

```php
// config/licensing.production.php
return [
    'policies' => [
        'grace_days' => 7,
        'over_limit' => 'reject',
    ],
    'crypto' => [
        'keystore' => [
            'driver' => 'vault', // Use Vault in production
        ],
    ],
    'audit' => [
        'enabled' => true,
        'retention_days' => 365,
    ],
];
```

### Loading Environment Config

```php
// AppServiceProvider.php
public function boot()
{
    $env = app()->environment();
    $envConfig = config_path("licensing.{$env}.php");
    
    if (file_exists($envConfig)) {
        $this->mergeConfigFrom($envConfig, 'licensing');
    }
}
```

## Validation Rules

### Custom Validation Rules

Create custom validation rules for licensing:

```php
namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use LucaLongo\Licensing\Models\License;

class ValidActivationKey implements Rule
{
    public function passes($attribute, $value)
    {
        if (!is_string($value)) {
            return false;
        }
        
        // Check format (XXXX-XXXX-XXXX-XXXX)
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $value)) {
            return false;
        }
        
        // Check if exists
        $license = License::findByKey($value);
        
        return $license !== null;
    }
    
    public function message()
    {
        return 'The activation key is invalid.';
    }
}

// Usage in controller
$request->validate([
    'activation_key' => ['required', new ValidActivationKey],
]);
```

## Performance Tuning

### Cache Configuration

```php
'cache' => [
    // Cache driver for licensing data
    'driver' => 'redis',
    
    // Cache TTL for various data
    'ttl' => [
        'license_check' => 300, // 5 minutes
        'feature_flags' => 3600, // 1 hour
        'public_keys' => 86400, // 1 day
        'templates' => 3600, // 1 hour
    ],
    
    // Cache tags
    'tags' => [
        'licenses',
        'usages',
        'keys',
    ],
],
```

### Database Optimization

```php
'database' => [
    // Use read replicas for queries
    'use_read_replica' => true,
    
    // Connection pool size
    'pool_size' => 20,
    
    // Query timeout (seconds)
    'timeout' => 30,
    
    // Chunk size for batch operations
    'chunk_size' => 1000,
],
```

## Testing Configuration

### Test Environment

```php
// config/licensing.testing.php
return [
    'crypto' => [
        'algorithm' => 'ed25519',
        'keystore' => [
            'driver' => 'array', // In-memory for tests
        ],
    ],
    'offline_token' => [
        'ttl_days' => -1, // Negative for expired tokens in tests
    ],
    'rate_limit' => [
        'validate_per_minute' => 10000, // No real limits in tests
    ],
];
```

## Next Steps

- [Basic Usage](basic-usage.md) - Start using the configured system
- [API Reference](api/models.md) - Detailed API documentation
- [Security Guide](advanced/security.md) - Security best practices
- [Performance Guide](advanced/performance.md) - Optimization tips