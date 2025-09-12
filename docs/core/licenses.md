# 📜 Core Concepts - Licenses

Deep dive into the license model and its lifecycle management.

## License Architecture

### The License Model

The `License` model is the core entity of the licensing system. It uses:

- **ULIDs** for unique identifiers (sortable, URL-safe)
- **Polymorphic relationships** to attach to any model
- **Salted key hashing** for security
- **JSON metadata** for extensibility

```php
class License extends Model
{
    use HasUlids;
    
    // Core attributes
    protected $fillable = [
        'key_hash',           // SHA256 hash of activation key
        'status',             // Enum: pending, active, grace, expired, suspended, cancelled
        'licensable_type',    // Polymorphic owner type
        'licensable_id',      // Polymorphic owner ID
        'template_id',        // Optional template reference
        'activated_at',       // Activation timestamp
        'expires_at',         // Expiration date
        'max_usages',         // Maximum seats/devices
        'meta',               // JSON metadata storage
    ];
}
```

## License Lifecycle

### State Transitions

```
┌─────────┐     activate()     ┌────────┐
│ Pending ├───────────────────>│ Active │
└─────────┘                     └───┬────┘
                                    │
                          expires   │
                                    ▼
                              ┌───────────┐
                              │   Grace   │
                              └─────┬─────┘
                                    │
                        grace expires│
                                    ▼
                              ┌───────────┐
                              │  Expired  │
                              └───────────┘

     suspend()              cancel()
Active ────────> Suspended  Active ────> Cancelled
```

### Lifecycle Methods

```php
// Activation
$license->activate();
// Sets status to 'active'
// Sets activated_at timestamp
// Fires LicenseActivated event

// Suspension (temporary)
$license->suspend();
// Can be reactivated later

// Cancellation (permanent)
$license->cancel();
// Terminal state

// Grace period transition
$license->transitionToGrace();
// Automatic when expired but within grace period

// Full expiration
$license->transitionToExpired();
// When grace period expires
```

## Key Management

### Activation Key Generation

```php
use Illuminate\Support\Str;

class ActivationKeyService
{
    /**
     * Generate a secure activation key
     */
    public function generate(): string
    {
        // Option 1: Simple random string
        $key = strtoupper(Str::random(20));
        
        // Option 2: Formatted segments
        $segments = [];
        for ($i = 0; $i < 5; $i++) {
            $segments[] = strtoupper(Str::random(4));
        }
        return implode('-', $segments); // XXXX-XXXX-XXXX-XXXX-XXXX
        
        // Option 3: Custom format with checksum
        return $this->generateWithChecksum();
    }
    
    /**
     * Generate key with checksum for validation
     */
    private function generateWithChecksum(): string
    {
        $payload = strtoupper(Str::random(16));
        $checksum = substr(hash('crc32', $payload), 0, 4);
        
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($payload, 0, 4),
            substr($payload, 4, 4),
            substr($payload, 8, 4),
            substr($payload, 12, 4),
            strtoupper($checksum)
        );
    }
    
    /**
     * Validate key format
     */
    public function validate(string $key): bool
    {
        // Remove formatting
        $clean = str_replace('-', '', $key);
        
        if (strlen($clean) !== 20) {
            return false;
        }
        
        // Extract checksum
        $payload = substr($clean, 0, 16);
        $checksum = substr($clean, 16, 4);
        
        // Verify checksum
        $expected = substr(hash('crc32', $payload), 0, 4);
        
        return strcasecmp($checksum, $expected) === 0;
    }
}
```

### Key Storage and Verification

```php
// Storing activation keys
$activationKey = $keyService->generate();

$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    // ... other fields
]);

// Store the plain key securely for the customer
// Options:
// 1. Email it immediately
// 2. Show once and never store
// 3. Encrypt and store temporarily

// Verification process
$providedKey = 'XXXX-XXXX-XXXX-XXXX-XXXX';

// Find license by key hash
$license = License::findByKey($providedKey);

if ($license && $license->verifyKey($providedKey)) {
    // Key is valid
    $license->activate();
}
```

## Polymorphic Licensing

### Setting Up Licensable Models

```php
// User model
class User extends Model
{
    use HasLicenses;
    
    public function licenses()
    {
        return $this->morphMany(License::class, 'licensable');
    }
    
    public function activeLicense()
    {
        return $this->licenses()
            ->whereIn('status', ['active', 'grace'])
            ->latest('activated_at')
            ->first();
    }
}

// Organization model
class Organization extends Model
{
    use HasLicenses;
    
    public function licenses()
    {
        return $this->morphMany(License::class, 'licensable');
    }
    
    public function getSubscription()
    {
        return $this->activeLicense()?->template;
    }
}

// Device model (for hardware licensing)
class Device extends Model
{
    use HasLicenses;
    
    public function licenses()
    {
        return $this->morphMany(License::class, 'licensable');
    }
}
```

### Creating Licenses for Different Entities

```php
// For a user
$userLicense = License::create([
    'key_hash' => License::hashKey($key),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'max_usages' => 3,
]);

// For an organization
$orgLicense = License::create([
    'key_hash' => License::hashKey($key),
    'licensable_type' => Organization::class,
    'licensable_id' => $organization->id,
    'max_usages' => 100, // Enterprise license
]);

// For a device
$deviceLicense = License::create([
    'key_hash' => License::hashKey($key),
    'licensable_type' => Device::class,
    'licensable_id' => $device->id,
    'max_usages' => 1, // Single device
]);
```

## Metadata System

### Using License Metadata

```php
// Store custom data in meta field
$license = License::create([
    'key_hash' => License::hashKey($key),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'meta' => [
        // Product information
        'product_name' => 'Professional Suite',
        'product_version' => '2.0',
        'product_edition' => 'Pro',
        
        // Purchase information
        'purchase_date' => now()->toDateString(),
        'purchase_order' => 'PO-2024-001',
        'invoice_number' => 'INV-2024-1234',
        
        // Custom policies
        'policies' => [
            'grace_days' => 30,
            'auto_renew' => true,
            'upgrade_allowed' => true,
        ],
        
        // Feature overrides
        'features' => [
            'api_access' => true,
            'white_label' => false,
            'priority_support' => true,
        ],
        
        // Entitlement overrides
        'entitlements' => [
            'api_calls_per_month' => 50000,
            'storage_gb' => 500,
        ],
        
        // Support information
        'support_level' => 'premium',
        'support_expires' => '2025-12-31',
        
        // Custom fields
        'company_size' => 'enterprise',
        'industry' => 'technology',
        'region' => 'north_america',
    ],
]);

// Access metadata
$productName = $license->meta['product_name'];
$isAutoRenew = $license->meta['policies']['auto_renew'] ?? false;

// Update metadata
$license->meta = array_merge($license->meta ?? [], [
    'last_renewal' => now()->toDateString(),
    'renewal_count' => ($license->meta['renewal_count'] ?? 0) + 1,
]);
$license->save();
```

### Policy Override System

```php
class License extends Model
{
    /**
     * Get policy value with fallback to config
     */
    public function getPolicy(string $key): mixed
    {
        // Check license-specific override
        if (isset($this->meta['policies'][$key])) {
            return $this->meta['policies'][$key];
        }
        
        // Check template override
        if ($this->template) {
            $templateConfig = $this->template->base_configuration;
            if (isset($templateConfig['policies'][$key])) {
                return $templateConfig['policies'][$key];
            }
        }
        
        // Fall back to global config
        return config("licensing.policies.{$key}");
    }
}

// Usage
$graceDays = $license->getPolicy('grace_days'); // 30 (from meta)
$overLimit = $license->getPolicy('over_limit'); // 'reject' (from config)
```

## Expiration Management

### Expiration Checking

```php
class ExpirationService
{
    /**
     * Check licenses nearing expiration
     */
    public function checkExpiring(int $daysAhead = 30): Collection
    {
        return License::where('status', 'active')
            ->whereBetween('expires_at', [
                now(),
                now()->addDays($daysAhead)
            ])
            ->get()
            ->groupBy(function ($license) {
                $days = $license->daysUntilExpiration();
                
                if ($days <= 1) return 'critical';
                if ($days <= 7) return 'urgent';
                if ($days <= 14) return 'warning';
                return 'notice';
            });
    }
    
    /**
     * Process expired licenses
     */
    public function processExpired(): void
    {
        // Transition active licenses to grace
        License::where('status', 'active')
            ->where('expires_at', '<', now())
            ->each(function ($license) {
                $license->transitionToGrace();
                event(new LicenseExpired($license));
            });
        
        // Transition grace licenses to expired
        License::where('status', 'grace')
            ->each(function ($license) {
                if ($license->gracePeriodExpired()) {
                    $license->transitionToExpired();
                    event(new GracePeriodExpired($license));
                }
            });
    }
}
```

### Grace Period Implementation

```php
trait HandlesGracePeriod
{
    /**
     * Check if in grace period
     */
    public function isInGracePeriod(): bool
    {
        return $this->status === LicenseStatus::Grace;
    }
    
    /**
     * Get remaining grace days
     */
    public function remainingGraceDays(): int
    {
        if (!$this->isInGracePeriod()) {
            return 0;
        }
        
        $graceDays = $this->getGraceDays();
        $daysSinceExpiration = now()->diffInDays($this->expires_at);
        
        return max(0, $graceDays - $daysSinceExpiration);
    }
    
    /**
     * Apply grace period restrictions
     */
    public function applyGraceRestrictions(): array
    {
        return [
            'read_only' => false,
            'limited_features' => true,
            'show_warnings' => true,
            'allow_renewal' => true,
            'block_new_operations' => true,
        ];
    }
}
```

## License Querying

### Common Query Patterns

```php
class LicenseQueryBuilder
{
    /**
     * Active licenses for an entity
     */
    public function activeFor(Model $entity): Builder
    {
        return License::where('licensable_type', get_class($entity))
            ->where('licensable_id', $entity->id)
            ->whereIn('status', ['active', 'grace']);
    }
    
    /**
     * Licenses expiring soon
     */
    public function expiringSoon(int $days = 30): Builder
    {
        return License::where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->orderBy('expires_at');
    }
    
    /**
     * Licenses by template/tier
     */
    public function byTemplate(string $templateSlug): Builder
    {
        return License::whereHas('template', function ($query) use ($templateSlug) {
            $query->where('slug', $templateSlug);
        });
    }
    
    /**
     * Over-utilized licenses
     */
    public function overUtilized(): Builder
    {
        return License::whereRaw('
            (SELECT COUNT(*) FROM license_usages 
             WHERE license_id = licenses.id 
             AND status = "active") >= max_usages
        ');
    }
    
    /**
     * Recently activated
     */
    public function recentlyActivated(int $days = 7): Builder
    {
        return License::where('activated_at', '>=', now()->subDays($days))
            ->orderBy('activated_at', 'desc');
    }
}
```

### Advanced Filtering

```php
// Complex license search
$licenses = License::query()
    ->when($request->status, function ($query, $status) {
        $query->where('status', $status);
    })
    ->when($request->template, function ($query, $template) {
        $query->where('template_id', $template);
    })
    ->when($request->expiring_days, function ($query, $days) {
        $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
    })
    ->when($request->search, function ($query, $search) {
        $query->where(function ($q) use ($search) {
            $q->where('uid', 'like', "%{$search}%")
              ->orWhereHas('licensable', function ($q) use ($search) {
                  $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
              });
        });
    })
    ->withCount('activeUsages')
    ->with(['licensable', 'template'])
    ->paginate();
```

## Performance Optimization

### Eager Loading

```php
// Optimize queries with relationships
$licenses = License::with([
    'licensable',
    'template',
    'usages' => function ($query) {
        $query->where('status', 'active');
    },
    'renewals' => function ($query) {
        $query->latest()->limit(5);
    }
])->get();
```

### Caching Strategies

```php
class CachedLicense
{
    /**
     * Get cached license with features
     */
    public static function get(string $licenseId): ?License
    {
        return Cache::remember(
            "license:{$licenseId}",
            300, // 5 minutes
            function () use ($licenseId) {
                return License::with(['template', 'usages'])
                    ->find($licenseId);
            }
        );
    }
    
    /**
     * Clear license cache
     */
    public static function clear(string $licenseId): void
    {
        Cache::forget("license:{$licenseId}");
        Cache::forget("license:{$licenseId}:features");
        Cache::forget("license:{$licenseId}:entitlements");
    }
}
```

## Next Steps

- [Usage & Seats](usage-seats.md) - Device and seat management
- [Templates & Tiers](templates-tiers.md) - Template-based licensing
- [Renewals](renewals.md) - License renewal system
- [API Reference](../api/models.md) - Complete model documentation