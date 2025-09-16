# 💺 Core Concepts - Usage & Seats

Complete guide to managing license usage, device registration, and seat allocation.

## Understanding Usage & Seats

### Terminology

- **Usage**: A single consumed seat (device, VM, service, user session)
- **Seat**: An allocation slot for a usage
- **Fingerprint**: Unique, stable identifier for a usage
- **Max Usages**: Maximum number of concurrent seats allowed
- **Active Usage**: Currently registered and valid seat consumption

### The LicenseUsage Model

```php
class LicenseUsage extends Model
{
    protected $fillable = [
        'license_id',         // Associated license
        'usage_fingerprint',  // Unique device/client identifier
        'status',            // active|revoked
        'registered_at',     // Initial registration
        'last_seen_at',      // Last heartbeat
        'revoked_at',        // Revocation timestamp
        'client_type',       // desktop|mobile|server|web
        'name',              // Human-readable name
        'ip',                // Optional IP address
        'user_agent',        // Optional user agent
        'meta',              // JSON metadata
    ];
}
```

## Device Fingerprinting

### Fingerprint Generation Strategies

```php
namespace App\Services;

use Illuminate\Http\Request;

class FingerprintService
{
    /**
     * Web-based fingerprint
     */
    public function generateWebFingerprint(Request $request): string
    {
        $components = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accept_language' => $request->header('Accept-Language'),
            'accept_encoding' => $request->header('Accept-Encoding'),
            'dnt' => $request->header('DNT'),
        ];
        
        return $this->hash($components);
    }
    
    /**
     * Hardware-based fingerprint
     */
    public function generateHardwareFingerprint(array $hardware): string
    {
        $components = [
            'cpu_id' => $hardware['cpu_id'] ?? null,
            'motherboard_serial' => $hardware['motherboard_serial'] ?? null,
            'mac_addresses' => $hardware['mac_addresses'] ?? [],
            'disk_serial' => $hardware['disk_serial'] ?? null,
            'machine_id' => $hardware['machine_id'] ?? null,
        ];
        
        // Sort for consistency
        ksort($components);
        
        return $this->hash($components);
    }
    
    /**
     * Virtual machine fingerprint
     */
    public function generateVMFingerprint(array $vmInfo): string
    {
        $components = [
            'vm_uuid' => $vmInfo['uuid'],
            'hypervisor' => $vmInfo['hypervisor'],
            'instance_id' => $vmInfo['instance_id'] ?? null,
            'region' => $vmInfo['region'] ?? null,
        ];
        
        return $this->hash($components);
    }
    
    /**
     * Mobile device fingerprint
     */
    public function generateMobileFingerprint(array $device): string
    {
        $components = [
            'device_id' => $device['device_id'], // iOS: identifierForVendor, Android: ANDROID_ID
            'model' => $device['model'],
            'os_version' => $device['os_version'],
            'app_instance_id' => $device['app_instance_id'] ?? null,
        ];
        
        return $this->hash($components);
    }
    
    /**
     * Container/Docker fingerprint
     */
    public function generateContainerFingerprint(array $container): string
    {
        $components = [
            'container_id' => $container['id'],
            'image' => $container['image'],
            'hostname' => $container['hostname'],
            'labels' => $container['labels'] ?? [],
        ];
        
        return $this->hash($components);
    }
    
    /**
     * Create hash from components
     */
    private function hash(array $components): string
    {
        // Remove null values
        $components = array_filter($components, fn($v) => $v !== null);
        
        // Serialize consistently
        $serialized = json_encode($components, JSON_CANONICAL);
        
        // Create hash
        return hash('sha256', $serialized);
    }
    
    /**
     * Validate fingerprint format
     */
    public function validate(string $fingerprint): bool
    {
        // Must be 64 character hex string (SHA256)
        return preg_match('/^[a-f0-9]{64}$/i', $fingerprint) === 1;
    }
}
```

### Privacy-Conscious Fingerprinting

```php
class PrivacyFingerprintService
{
    /**
     * Generate fingerprint without PII
     */
    public function generate(array $components): string
    {
        // Remove PII
        $safe = $this->removePII($components);
        
        // Add salt for privacy
        $salted = $this->addSalt($safe);
        
        // Generate fingerprint
        return hash('sha256', json_encode($salted));
    }
    
    private function removePII(array $components): array
    {
        $piiKeys = ['email', 'name', 'username', 'full_ip', 'location'];
        
        foreach ($piiKeys as $key) {
            unset($components[$key]);
        }
        
        // Anonymize IP (keep subnet only)
        if (isset($components['ip'])) {
            $components['ip'] = $this->anonymizeIP($components['ip']);
        }
        
        return $components;
    }
    
    private function anonymizeIP(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Keep first 3 octets for IPv4
            $parts = explode('.', $ip);
            return implode('.', array_slice($parts, 0, 3)) . '.0';
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Keep network prefix for IPv6
            return substr($ip, 0, 19) . '::/64';
        }
        
        return 'unknown';
    }
    
    private function addSalt(array $components): array
    {
        $components['_salt'] = config('app.key');
        return $components;
    }
}
```

## Usage Registration

### The UsageRegistrarService

```php
use LucaLongo\Licensing\Services\UsageRegistrarService;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

class UsageManager
{
    public function __construct(
        private UsageRegistrarService $registrar
    ) {}
    
    /**
     * Register new usage with validation
     */
    public function register(License $license, string $fingerprint, array $metadata = []): LicenseUsage
    {
        // Check if already registered
        $existing = $this->registrar->findByFingerprint($license, $fingerprint);
        
        if ($existing && $existing->isActive()) {
            // Update heartbeat and return
            $this->registrar->heartbeat($existing);
            return $existing;
        }
        
        // Check if can register
        if (!$this->registrar->canRegister($license, $fingerprint)) {
            throw new CannotRegisterException('Registration not allowed');
        }
        
        // Register with pessimistic locking
        return DB::transaction(function () use ($license, $fingerprint, $metadata) {
            // Reload + lock the row to avoid "dirty" model state
            $locked = $license->newQuery()
                ->lockForUpdate()
                ->find($license->getKey());

            if (! $locked) {
                throw new \RuntimeException('License not found during registration');
            }

            // Check seat availability using the locked instance
            $activeCount = $locked->activeUsages()->count();

            if ($activeCount >= $locked->max_usages) {
                // Handle over-limit
                return $this->handleOverLimit($locked, $fingerprint, $metadata);
            }

            // Pass explicit metadata (useful for CLI/jobs without HTTP request)
            $metadataWithContext = array_merge([
                'ip' => $metadata['ip'] ?? null,
                'user_agent' => $metadata['user_agent'] ?? null,
            ], $metadata);

            return $this->registrar->register($locked, $fingerprint, $metadataWithContext);
        });
    }
    
    /**
     * Handle over-limit scenarios
     */
    private function handleOverLimit(License $license, string $fingerprint, array $metadata): LicenseUsage
    {
        $policy = $license->getOverLimitPolicy();
        
        switch ($policy) {
            case OverLimitPolicy::Reject:
                throw new UsageLimitReachedException(
                    "License limit of {$license->max_usages} devices reached"
                );
                
            case OverLimitPolicy::AutoReplaceOldest:
                // Find and revoke oldest
                $oldest = $license->activeUsages()
                    ->orderBy('last_seen_at', 'asc')
                    ->first();
                
                $this->registrar->revoke($oldest, 'Auto-replaced by new device');
                
                // Register new
                return $this->registrar->register($license, $fingerprint, $metadata);
                
            default:
                throw new \Exception("Unknown over-limit policy: {$policy->value}");
        }
    }
}
```

### Bulk Registration

```php
class BulkUsageService
{
    /**
     * Register multiple usages at once
     */
    public function registerBulk(License $license, array $usages): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
        ];
        
        DB::transaction(function () use ($license, $usages, &$results) {
            foreach ($usages as $usage) {
                try {
                    $registered = $this->registrar->register(
                        $license,
                        $usage['fingerprint'],
                        $usage['metadata'] ?? []
                    );
                    
                    $results['successful'][] = $registered;
                    
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'fingerprint' => $usage['fingerprint'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });
        
        return $results;
    }
    
    /**
     * Pre-allocate seats for future use
     */
    public function preAllocateSeats(License $license, int $count): array
    {
        $seats = [];
        
        for ($i = 0; $i < $count; $i++) {
            $fingerprint = 'reserved_' . Str::uuid();
            
            $seats[] = LicenseUsage::create([
                'license_id' => $license->id,
                'usage_fingerprint' => $fingerprint,
                'status' => 'reserved',
                'name' => "Reserved Seat #{$i + 1}",
                'meta' => [
                    'reserved_at' => now(),
                    'reserved_until' => now()->addDays(30),
                ],
            ]);
        }
        
        return $seats;
    }
}
```

## Heartbeat & Activity Tracking

### Heartbeat Management

```php
class HeartbeatService
{
    private int $staleThreshold = 3600; // 1 hour
    private int $inactiveThreshold = 86400; // 24 hours
    
    /**
     * Update usage heartbeat
     */
    public function heartbeat(LicenseUsage $usage, array $metadata = []): void
    {
        $usage->update([
            'last_seen_at' => now(),
            'meta' => array_merge($usage->meta ?? [], $metadata),
        ]);
        
        // Check if was stale
        if ($this->wasStale($usage)) {
            event(new UsageReactivated($usage));
        }
        
        // Update cache
        Cache::put(
            "usage:heartbeat:{$usage->id}",
            now(),
            $this->staleThreshold
        );
    }
    
    /**
     * Batch heartbeat update
     */
    public function batchHeartbeat(array $usageIds): void
    {
        LicenseUsage::whereIn('id', $usageIds)
            ->update(['last_seen_at' => now()]);
    }
    
    /**
     * Check if usage is stale
     */
    public function isStale(LicenseUsage $usage): bool
    {
        $lastSeen = $usage->last_seen_at ?? $usage->registered_at;
        
        return $lastSeen->diffInSeconds(now()) > $this->staleThreshold;
    }
    
    /**
     * Check if usage is inactive
     */
    public function isInactive(LicenseUsage $usage): bool
    {
        $lastSeen = $usage->last_seen_at ?? $usage->registered_at;
        
        return $lastSeen->diffInSeconds(now()) > $this->inactiveThreshold;
    }
    
    /**
     * Get stale usages
     */
    public function getStaleUsages(): Collection
    {
        return LicenseUsage::where('status', 'active')
            ->where('last_seen_at', '<', now()->subSeconds($this->staleThreshold))
            ->get();
    }
    
    /**
     * Auto-revoke inactive usages
     */
    public function revokeInactive(): int
    {
        $count = 0;
        
        LicenseUsage::where('status', 'active')
            ->where('last_seen_at', '<', now()->subSeconds($this->inactiveThreshold))
            ->each(function ($usage) use (&$count) {
                $license = $usage->license;
                
                // Check if auto-revoke is enabled
                $autoRevokeDays = $license->getInactivityAutoRevokeDays();
                
                if ($autoRevokeDays === null) {
                    return;
                }
                
                $threshold = now()->subDays($autoRevokeDays);
                
                if ($usage->last_seen_at < $threshold) {
                    $usage->revoke('Auto-revoked due to inactivity');
                    $count++;
                }
            });
        
        return $count;
    }
}
```

### Activity Monitoring

```php
class UsageActivityMonitor
{
    /**
     * Get usage activity timeline
     */
    public function getActivityTimeline(LicenseUsage $usage, int $days = 7): array
    {
        $timeline = [];
        $start = now()->subDays($days)->startOfDay();
        
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            
            $timeline[$date->format('Y-m-d')] = $this->getActivityForDate($usage, $date);
        }
        
        return $timeline;
    }
    
    /**
     * Get activity metrics
     */
    public function getMetrics(License $license): array
    {
        $usages = $license->usages;
        
        return [
            'total_seats' => $license->max_usages,
            'active_seats' => $usages->where('status', 'active')->count(),
            'revoked_seats' => $usages->where('status', 'revoked')->count(),
            'utilization_rate' => $this->calculateUtilization($license),
            'average_session_duration' => $this->calculateAverageSession($usages),
            'most_active_device' => $this->getMostActiveDevice($usages),
            'least_active_device' => $this->getLeastActiveDevice($usages),
            'activity_heatmap' => $this->generateHeatmap($usages),
        ];
    }
    
    private function calculateUtilization(License $license): float
    {
        if ($license->max_usages === 0) {
            return 0;
        }
        
        $active = $license->activeUsages()->count();
        
        return round(($active / $license->max_usages) * 100, 2);
    }
}
```

## Seat Management Strategies

### Dynamic Seat Allocation

```php
class DynamicSeatAllocator
{
    /**
     * Allocate seat based on priority
     */
    public function allocate(License $license, string $fingerprint, int $priority = 0): LicenseUsage
    {
        // Get current usages with priority
        $usages = $license->activeUsages()
            ->orderBy('meta->priority', 'asc')
            ->orderBy('last_seen_at', 'asc')
            ->get();
        
        // Check if can allocate directly
        if ($usages->count() < $license->max_usages) {
            return $this->createUsage($license, $fingerprint, $priority);
        }
        
        // Find lower priority usage to replace
        $toReplace = $usages->first(function ($usage) use ($priority) {
            return ($usage->meta['priority'] ?? 0) < $priority;
        });
        
        if (!$toReplace) {
            throw new NoPriorityException('Cannot allocate seat with given priority');
        }
        
        // Replace lower priority
        $toReplace->revoke('Replaced by higher priority device');
        
        return $this->createUsage($license, $fingerprint, $priority);
    }
    
    /**
     * Floating license implementation
     */
    public function requestFloatingLicense(string $fingerprint): ?LicenseUsage
    {
        // Find available floating license
        $license = License::where('meta->license_type', 'floating')
            ->where('status', 'active')
            ->whereRaw('
                (SELECT COUNT(*) FROM license_usages 
                 WHERE license_id = licenses.id 
                 AND status = "active") < max_usages
            ')
            ->first();
        
        if (!$license) {
            return null;
        }
        
        // Allocate for limited time
        return LicenseUsage::create([
            'license_id' => $license->id,
            'usage_fingerprint' => $fingerprint,
            'status' => 'active',
            'registered_at' => now(),
            'last_seen_at' => now(),
            'meta' => [
                'floating' => true,
                'expires_at' => now()->addHours(8), // 8-hour session
            ],
        ]);
    }
    
    /**
     * Release floating license
     */
    public function releaseFloatingLicense(LicenseUsage $usage): void
    {
        if ($usage->meta['floating'] ?? false) {
            $usage->revoke('Floating license released');
        }
    }
}
```

### Named User Licensing

```php
class NamedUserLicensing
{
    /**
     * Assign license to specific user
     */
    public function assignToUser(License $license, User $user): LicenseUsage
    {
        // Check if user already has assignment
        $existing = $license->usages()
            ->where('meta->user_id', $user->id)
            ->where('status', 'active')
            ->first();
        
        if ($existing) {
            return $existing;
        }
        
        // Create named assignment
        return LicenseUsage::create([
            'license_id' => $license->id,
            'usage_fingerprint' => 'user_' . $user->id,
            'status' => 'active',
            'name' => $user->name,
            'registered_at' => now(),
            'meta' => [
                'type' => 'named_user',
                'user_id' => $user->id,
                'user_email' => $user->email,
            ],
        ]);
    }
    
    /**
     * Transfer named license
     */
    public function transferNamedLicense(LicenseUsage $usage, User $newUser): LicenseUsage
    {
        // Revoke old assignment
        $usage->revoke('Transferred to another user');
        
        // Create new assignment
        return $this->assignToUser($usage->license, $newUser);
    }
    
    /**
     * Get user's licenses
     */
    public function getUserLicenses(User $user): Collection
    {
        return LicenseUsage::where('meta->user_id', $user->id)
            ->where('status', 'active')
            ->with('license')
            ->get();
    }
}
```

## Concurrent Usage Control

### Concurrency Management

```php
class ConcurrencyController
{
    /**
     * Check concurrent usage limit
     */
    public function checkConcurrency(License $license, string $fingerprint): bool
    {
        // Get active sessions
        $activeSessions = Cache::get("license:{$license->id}:sessions", []);
        
        // Remove expired sessions
        $activeSessions = array_filter($activeSessions, function ($session) {
            return $session['expires_at'] > now();
        });
        
        // Check if fingerprint already has session
        if (isset($activeSessions[$fingerprint])) {
            // Extend existing session
            $activeSessions[$fingerprint]['expires_at'] = now()->addMinutes(30);
            Cache::put("license:{$license->id}:sessions", $activeSessions, 3600);
            return true;
        }
        
        // Check concurrent limit
        if (count($activeSessions) >= $license->max_usages) {
            return false;
        }
        
        // Create new session
        $activeSessions[$fingerprint] = [
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
        
        Cache::put("license:{$license->id}:sessions", $activeSessions, 3600);
        
        return true;
    }
    
    /**
     * Release concurrent session
     */
    public function releaseSession(License $license, string $fingerprint): void
    {
        $activeSessions = Cache::get("license:{$license->id}:sessions", []);
        
        unset($activeSessions[$fingerprint]);
        
        Cache::put("license:{$license->id}:sessions", $activeSessions, 3600);
    }
}
```

## Usage Policies

### Global vs Per-License Uniqueness

```php
class UniqueUsagePolicy
{
    /**
     * Check uniqueness based on scope
     */
    public function checkUniqueness(License $license, string $fingerprint): bool
    {
        $scope = $license->getUniqueUsageScope();
        
        switch ($scope) {
            case 'license':
                // Unique per license
                return !$license->usages()
                    ->where('usage_fingerprint', $fingerprint)
                    ->where('status', 'active')
                    ->exists();
                
            case 'global':
                // Unique globally
                return !LicenseUsage::where('usage_fingerprint', $fingerprint)
                    ->where('status', 'active')
                    ->exists();
                
            case 'organization':
                // Unique per organization
                $orgLicenses = License::where('licensable_type', $license->licensable_type)
                    ->where('licensable_id', $license->licensable_id)
                    ->pluck('id');
                
                return !LicenseUsage::whereIn('license_id', $orgLicenses)
                    ->where('usage_fingerprint', $fingerprint)
                    ->where('status', 'active')
                    ->exists();
                
            default:
                throw new \Exception("Unknown uniqueness scope: {$scope}");
        }
    }
}
```

## Usage Analytics

### Usage Reports

```php
class UsageReportGenerator
{
    /**
     * Generate usage report
     */
    public function generate(License $license, string $period = 'month'): array
    {
        return [
            'summary' => $this->getSummary($license),
            'timeline' => $this->getTimeline($license, $period),
            'devices' => $this->getDeviceBreakdown($license),
            'patterns' => $this->getUsagePatterns($license),
            'recommendations' => $this->getRecommendations($license),
        ];
    }
    
    private function getSummary(License $license): array
    {
        return [
            'total_seats' => $license->max_usages,
            'active_seats' => $license->activeUsages()->count(),
            'peak_usage' => $this->getPeakUsage($license),
            'average_usage' => $this->getAverageUsage($license),
            'utilization_rate' => $this->getUtilizationRate($license),
        ];
    }
    
    private function getUsagePatterns(License $license): array
    {
        $usages = $license->usages()
            ->where('registered_at', '>=', now()->subMonth())
            ->get();
        
        return [
            'peak_hours' => $this->identifyPeakHours($usages),
            'peak_days' => $this->identifyPeakDays($usages),
            'average_session_length' => $this->calculateSessionLength($usages),
            'churn_rate' => $this->calculateChurnRate($usages),
        ];
    }
    
    private function getRecommendations(License $license): array
    {
        $recommendations = [];
        
        $utilization = $this->getUtilizationRate($license);
        
        if ($utilization > 90) {
            $recommendations[] = [
                'type' => 'upgrade',
                'message' => 'Consider upgrading to more seats',
                'severity' => 'high',
            ];
        }
        
        if ($utilization < 30) {
            $recommendations[] = [
                'type' => 'downgrade',
                'message' => 'You may have too many seats',
                'severity' => 'low',
            ];
        }
        
        return $recommendations;
    }
}
```

## Next Steps

- [Templates & Tiers](templates-tiers.md) - Template-based licensing
- [Renewals](renewals.md) - License renewal system
- [Offline Verification](../features/offline-verification.md) - Token-based verification
- [API Reference](../api/models.md) - Complete API documentation
