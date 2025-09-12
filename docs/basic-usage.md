# 🎯 Basic Usage

Learn the fundamental operations of Laravel Licensing with practical examples.

## Table of Contents

1. [Creating Licenses](#creating-licenses)
2. [Activating Licenses](#activating-licenses)
3. [Registering Devices/Usages](#registering-devicesusages)
4. [Checking License Status](#checking-license-status)
5. [Managing Features & Entitlements](#managing-features--entitlements)
6. [Renewing Licenses](#renewing-licenses)
7. [Handling Expiration](#handling-expiration)
8. [Common Patterns](#common-patterns)

## Creating Licenses

### Simple License Creation

```php
use LucaLongo\Licensing\Models\License;
use Illuminate\Support\Str;

// Generate a unique activation key
$activationKey = strtoupper(Str::random(20));
$formattedKey = implode('-', str_split($activationKey, 4)); // XXXX-XXXX-XXXX-XXXX-XXXX

// Create the license
$license = License::create([
    'key_hash' => License::hashKey($activationKey),
    'licensable_type' => User::class,
    'licensable_id' => $user->id,
    'status' => LicenseStatus::Pending,
    'max_usages' => 5,
    'expires_at' => now()->addYear(),
    'meta' => [
        'product' => 'Professional Edition',
        'version' => '2.0',
    ],
]);

// Store the activation key securely for the customer
echo "Activation Key: {$formattedKey}";
```

### Using License Templates

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTemplate;

// Method 1: Create from template slug
$license = License::createFromTemplate('professional-annual', [
    'licensable_type' => Organization::class,
    'licensable_id' => $organization->id,
    'key_hash' => License::hashKey($activationKey),
]);

// Method 2: Create from template instance
$template = LicenseTemplate::findBySlug('enterprise-unlimited');
$license = License::createFromTemplate($template, [
    'licensable_type' => Organization::class,
    'licensable_id' => $organization->id,
]);

// The license inherits all template settings
echo $license->max_usages; // From template
echo $license->getFeatures(); // From template
echo $license->getEntitlements(); // From template
```

### Batch License Creation

```php
use Illuminate\Support\Facades\DB;

// Create multiple licenses in a transaction
DB::transaction(function () use ($organization, $quantity) {
    $licenses = [];
    
    for ($i = 0; $i < $quantity; $i++) {
        $activationKey = $this->generateUniqueKey();
        
        $licenses[] = License::create([
            'key_hash' => License::hashKey($activationKey),
            'licensable_type' => Organization::class,
            'licensable_id' => $organization->id,
            'status' => LicenseStatus::Pending,
            'max_usages' => 1,
            'expires_at' => now()->addYear(),
            'meta' => [
                'batch_id' => Str::uuid(),
                'index' => $i + 1,
            ],
        ]);
        
        // Store activation keys
        $this->storeActivationKey($organization, $activationKey);
    }
    
    return $licenses;
});
```

## Activating Licenses

### Basic Activation

```php
// Customer provides their activation key
$providedKey = request()->input('activation_key');

// Find the license
$license = License::findByKey($providedKey);

if (!$license) {
    return response()->json(['error' => 'Invalid activation key'], 404);
}

// Verify the key
if (!$license->verifyKey($providedKey)) {
    return response()->json(['error' => 'Invalid activation key'], 401);
}

// Check if already activated
if ($license->status !== LicenseStatus::Pending) {
    return response()->json(['error' => 'License already activated'], 409);
}

// Activate the license
$license->activate();

return response()->json([
    'message' => 'License activated successfully',
    'expires_at' => $license->expires_at,
    'features' => $license->getFeatures(),
]);
```

### Activation with Device Registration

```php
use LucaLongo\Licensing\Services\UsageRegistrarService;

public function activateWithDevice(Request $request, UsageRegistrarService $registrar)
{
    $validated = $request->validate([
        'activation_key' => 'required|string',
        'device_name' => 'required|string',
        'device_fingerprint' => 'required|string',
    ]);
    
    $license = License::findByKey($validated['activation_key']);
    
    if (!$license || !$license->verifyKey($validated['activation_key'])) {
        return response()->json(['error' => 'Invalid activation key'], 401);
    }
    
    // Activate if pending
    if ($license->status === LicenseStatus::Pending) {
        $license->activate();
    }
    
    // Register the device
    try {
        $usage = $registrar->register(
            $license,
            $validated['device_fingerprint'],
            [
                'name' => $validated['device_name'],
                'client_type' => 'desktop',
                'ip' => $request->ip(),
            ]
        );
        
        return response()->json([
            'message' => 'License activated and device registered',
            'license_id' => $license->uid,
            'usage_id' => $usage->id,
            'seats_remaining' => $license->getAvailableSeats(),
        ]);
        
    } catch (UsageLimitReachedException $e) {
        return response()->json([
            'error' => 'Device limit reached',
            'max_devices' => $license->max_usages,
        ], 403);
    }
}
```

## Registering Devices/Usages

### Register a New Device

```php
use LucaLongo\Licensing\Services\UsageRegistrarService;

$registrar = app(UsageRegistrarService::class);

// Generate device fingerprint
$fingerprint = hash('sha256', 
    $request->ip() . 
    $request->header('User-Agent') . 
    $request->input('hardware_id')
);

// Register the device
$usage = $registrar->register(
    $license,
    $fingerprint,
    [
        'name' => 'John\'s MacBook Pro',
        'client_type' => 'desktop',
        'meta' => [
            'os' => 'macOS',
            'version' => '14.0',
            'app_version' => '2.1.0',
        ],
    ]
);

// Update heartbeat periodically
$registrar->heartbeat($usage);
```

### Managing Multiple Devices

```php
// List all devices for a license
$devices = $license->activeUsages()
    ->select('id', 'name', 'last_seen_at', 'registered_at')
    ->get()
    ->map(function ($usage) {
        return [
            'id' => $usage->id,
            'name' => $usage->name,
            'last_active' => $usage->last_seen_at->diffForHumans(),
            'registered' => $usage->registered_at->format('M d, Y'),
        ];
    });

// Remove a device
$usage = $license->usages()->find($deviceId);
if ($usage) {
    $registrar->revoke($usage, 'Removed by user');
}

// Check if can add more devices
if ($license->hasAvailableSeats()) {
    $availableSeats = $license->getAvailableSeats();
    echo "You can add {$availableSeats} more devices";
}
```

### Auto-Replace Oldest Device

```php
// Configure in config/licensing.php
'policies' => [
    'over_limit' => 'auto_replace_oldest',
],

// Or handle manually
if (!$license->hasAvailableSeats()) {
    $oldestUsage = $license->activeUsages()
        ->orderBy('last_seen_at', 'asc')
        ->first();
    
    $registrar->revoke($oldestUsage, 'Auto-replaced by new device');
}

// Now register new device
$newUsage = $registrar->register($license, $newFingerprint, $metadata);
```

## Checking License Status

### Basic Status Check

```php
// Check if license is usable (active or in grace period)
if ($license->isUsable()) {
    // Allow access
}

// Check specific status
if ($license->status === LicenseStatus::Active) {
    // Fully active
}

if ($license->isInGracePeriod()) {
    // In grace period - show warning
    $daysRemaining = $license->getGraceDays() - $license->daysUntilExpiration();
    echo "License expired. Grace period: {$daysRemaining} days remaining";
}

// Check expiration
if ($license->isExpired()) {
    $daysSinceExpiration = abs($license->daysUntilExpiration());
    echo "License expired {$daysSinceExpiration} days ago";
} else {
    $daysUntilExpiration = $license->daysUntilExpiration();
    echo "License expires in {$daysUntilExpiration} days";
}
```

### Middleware for License Validation

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireActiveLicense
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return redirect()->route('login');
        }
        
        $license = $user->licenses()
            ->whereIn('status', [LicenseStatus::Active, LicenseStatus::Grace])
            ->first();
        
        if (!$license) {
            return redirect()->route('license.expired')
                ->with('error', 'No active license found');
        }
        
        if (!$license->isUsable()) {
            return redirect()->route('license.expired')
                ->with('error', 'Your license has expired');
        }
        
        // Add license to request for easy access
        $request->merge(['license' => $license]);
        
        // Show warning if expiring soon
        if ($license->daysUntilExpiration() <= 7) {
            session()->flash('warning', 
                "Your license expires in {$license->daysUntilExpiration()} days"
            );
        }
        
        return $next($request);
    }
}
```

## Managing Features & Entitlements

### Checking Features

```php
// Check if a feature is enabled
if ($license->hasFeature('advanced_analytics')) {
    // Show advanced analytics dashboard
}

// Get all features
$features = $license->getFeatures();
foreach ($features as $feature => $enabled) {
    if ($enabled) {
        echo "✓ {$feature}";
    }
}

// Feature-gated functionality
class AnalyticsController extends Controller
{
    public function advanced(Request $request)
    {
        $license = $request->license;
        
        if (!$license->hasFeature('advanced_analytics')) {
            return redirect()->route('upgrade')
                ->with('error', 'This feature requires an upgrade');
        }
        
        return view('analytics.advanced');
    }
}
```

### Working with Entitlements

```php
// Get specific entitlement
$apiCallsLimit = $license->getEntitlement('api_calls_per_day');
$storageLimit = $license->getEntitlement('storage_gb');
$teamMembersLimit = $license->getEntitlement('team_members');

// Check against limits
$currentApiCalls = Cache::get("api_calls:{$license->id}:" . today()->format('Y-m-d'), 0);

if ($apiCallsLimit !== -1 && $currentApiCalls >= $apiCallsLimit) {
    return response()->json([
        'error' => 'API call limit exceeded',
        'limit' => $apiCallsLimit,
        'reset_at' => now()->endOfDay(),
    ], 429);
}

// Track usage
Cache::increment("api_calls:{$license->id}:" . today()->format('Y-m-d'));
```

### Dynamic Feature Flags

```php
class FeatureService
{
    private $license;
    
    public function __construct(License $license)
    {
        $this->license = $license;
    }
    
    public function enabled(string $feature): bool
    {
        return $this->license->hasFeature($feature);
    }
    
    public function when(string $feature, callable $callback, callable $default = null)
    {
        if ($this->enabled($feature)) {
            return $callback();
        }
        
        return $default ? $default() : null;
    }
    
    public function unless(string $feature, callable $callback)
    {
        if (!$this->enabled($feature)) {
            return $callback();
        }
    }
}

// Usage
$features = new FeatureService($license);

$features->when('export_pdf', function () {
    // Enable PDF export
});

$features->unless('watermark_disabled', function () {
    // Add watermark to exports
});
```

## Renewing Licenses

### Simple Renewal

```php
// Extend license by one year
$license->renew(now()->addYear());

// Extend from current expiration
$newExpiration = $license->expires_at->addYear();
$license->renew($newExpiration);

// Renewal with payment information
$license->renew(now()->addYear(), [
    'amount_cents' => 9900,
    'currency' => 'USD',
    'notes' => 'Annual renewal - Professional Plan',
]);
```

### Renewal Workflow

```php
public function processRenewal(Request $request, License $license)
{
    // Check if renewable
    if (!$license->status->canRenew()) {
        return back()->with('error', 'License cannot be renewed');
    }
    
    // Process payment (simplified)
    $payment = $this->processPayment($request, $license);
    
    if (!$payment->successful) {
        return back()->with('error', 'Payment failed');
    }
    
    // Calculate new expiration
    $currentExpiration = $license->expires_at ?? now();
    $newExpiration = $currentExpiration->addYear();
    
    // Renew the license
    $license->renew($newExpiration, [
        'amount_cents' => $payment->amount_cents,
        'currency' => $payment->currency,
        'notes' => "Payment ID: {$payment->id}",
    ]);
    
    // Send confirmation
    Mail::to($license->licensable)->send(
        new LicenseRenewedMail($license)
    );
    
    return redirect()->route('license.show', $license)
        ->with('success', 'License renewed successfully');
}
```

### Auto-Renewal

```php
// Schedule in app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->job(new ProcessAutoRenewals)->daily();
}

// Job implementation
class ProcessAutoRenewals implements ShouldQueue
{
    public function handle()
    {
        $licenses = License::where('status', LicenseStatus::Active)
            ->where('expires_at', '<=', now()->addDays(7))
            ->whereJsonContains('meta->auto_renew', true)
            ->get();
        
        foreach ($licenses as $license) {
            try {
                // Process payment
                $payment = $this->chargeStoredPaymentMethod($license);
                
                // Renew license
                $license->renew(
                    $license->expires_at->addYear(),
                    ['payment_id' => $payment->id]
                );
                
                // Notify customer
                event(new LicenseAutoRenewed($license));
                
            } catch (\Exception $e) {
                // Handle failure
                event(new AutoRenewalFailed($license, $e->getMessage()));
            }
        }
    }
}
```

## Handling Expiration

### Grace Period Management

```php
// Check and transition to grace period
if ($license->status === LicenseStatus::Active && $license->isExpired()) {
    $license->transitionToGrace();
    
    // Notify user
    Mail::to($license->licensable)->send(
        new LicenseInGracePeriodMail($license)
    );
}

// Check if grace period expired
if ($license->gracePeriodExpired()) {
    $license->transitionToExpired();
    
    // Disable access
    $license->usages()->update(['status' => UsageStatus::Revoked]);
}
```

### Expiration Warnings

```php
// Schedule expiration warnings
class SendExpirationWarnings implements ShouldQueue
{
    public function handle()
    {
        $warningDays = [30, 14, 7, 1];
        
        foreach ($warningDays as $days) {
            $licenses = License::where('status', LicenseStatus::Active)
                ->whereDate('expires_at', now()->addDays($days))
                ->get();
            
            foreach ($licenses as $license) {
                event(new LicenseExpiringSoon($license, $days));
            }
        }
    }
}
```

## Common Patterns

### License Key Generator

```php
class LicenseKeyGenerator
{
    public static function generate(): string
    {
        // Format: XXXX-XXXX-XXXX-XXXX
        $segments = [];
        
        for ($i = 0; $i < 4; $i++) {
            $segments[] = strtoupper(Str::random(4));
        }
        
        return implode('-', $segments);
    }
    
    public static function generateBatch(int $count): array
    {
        $keys = [];
        
        while (count($keys) < $count) {
            $key = self::generate();
            
            // Ensure uniqueness
            if (!License::findByKey($key)) {
                $keys[] = $key;
            }
        }
        
        return $keys;
    }
}
```

### License Repository Pattern

```php
class LicenseRepository
{
    public function findActive(Model $licensable): ?License
    {
        return License::where('licensable_type', get_class($licensable))
            ->where('licensable_id', $licensable->id)
            ->whereIn('status', [LicenseStatus::Active, LicenseStatus::Grace])
            ->first();
    }
    
    public function findByKeyForUser(string $key, User $user): ?License
    {
        $license = License::findByKey($key);
        
        if (!$license) {
            return null;
        }
        
        // Verify ownership
        if ($license->licensable_type === User::class 
            && $license->licensable_id === $user->id) {
            return $license;
        }
        
        return null;
    }
    
    public function getExpiringLicenses(int $days = 30): Collection
    {
        return License::where('status', LicenseStatus::Active)
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->orderBy('expires_at')
            ->get();
    }
}
```

### License Validation Service

```php
class LicenseValidationService
{
    public function validate(License $license): ValidationResult
    {
        $result = new ValidationResult();
        
        // Check status
        if (!$license->isUsable()) {
            $result->addError('License is not active');
        }
        
        // Check expiration
        if ($license->isExpired()) {
            if ($license->isInGracePeriod()) {
                $result->addWarning('License is in grace period');
            } else {
                $result->addError('License has expired');
            }
        }
        
        // Check usage limits
        if (!$license->hasAvailableSeats()) {
            $result->addWarning('No available seats');
        }
        
        // Check device registration
        $currentDevice = $this->getCurrentDeviceFingerprint();
        $usage = $license->usages()
            ->where('usage_fingerprint', $currentDevice)
            ->first();
        
        if (!$usage || !$usage->isActive()) {
            $result->addError('Device not registered');
        }
        
        return $result;
    }
}
```

## Next Steps

- [Core Concepts - Licenses](core/licenses.md) - Deep dive into license management
- [Templates & Tiers](core/templates-tiers.md) - Template-based licensing
- [Offline Verification](features/offline-verification.md) - Offline token system
- [API Reference](api/models.md) - Complete API documentation