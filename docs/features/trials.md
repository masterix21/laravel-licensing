# Trials

The trial system allows potential customers to evaluate your software before purchasing. It provides time-limited access with optional feature restrictions and usage limitations, designed to encourage conversion to paid licenses.

## Table of Contents

- [Overview](#overview)
- [Trial Configuration](#trial-configuration)
- [Starting Trials](#starting-trials)
- [Trial Management](#trial-management)
- [Feature Restrictions](#feature-restrictions)
- [Usage Limitations](#usage-limitations)
- [Trial Extensions](#trial-extensions)
- [Conversion Tracking](#conversion-tracking)
- [Best Practices](#best-practices)

## Overview

Trials provide temporary access to your software with configurable limitations. The system supports:

- **Time-based trials**: Fixed duration with automatic expiration
- **Feature restrictions**: Disable specific features during trial
- **Usage limitations**: Limit API calls, storage, or other metrics
- **Extension capabilities**: Allow trial period extensions
- **Conversion tracking**: Monitor trial-to-paid conversion rates

```php
use LucaLongo\Licensing\Services\TrialService;

$trialService = app(TrialService::class);

// Start a 14-day trial
$trial = $trialService->startTrial($license, 14, [
    'api_calls_per_month' => 1000,
    'storage_gb' => 1,
], [
    'premium_support',
    'advanced_analytics'
]);
```

## Trial Configuration

### Global Trial Settings

Configure default trial behavior in `config/licensing.php`:

```php
return [
    'trials' => [
        'enabled' => true,
        'default_duration_days' => 14,
        'allow_extensions' => true,
        'max_extension_days' => 7,
        'prevent_reset_attempts' => true,
        'default_limitations' => [
            'api_calls_per_month' => 1000,
            'storage_gb' => 1,
            'projects' => 3,
        ],
        'default_feature_restrictions' => [
            'premium_support',
            'white_labeling',
            'api_access',
        ],
        'conversion_tracking' => [
            'enabled' => true,
            'analytics_provider' => 'google_analytics',
        ],
    ],
];
```

### Per-Template Trial Settings

Templates can override global trial settings:

```php
$template = LicenseTemplate::create([
    'name' => 'Premium Plan',
    'base_configuration' => [
        'max_usages' => 5,
        'validity_days' => 365,
    ],
    'meta' => [
        'trial' => [
            'duration_days' => 30,  // Longer trial for premium
            'limitations' => [
                'api_calls_per_month' => 5000,  // More generous limits
                'storage_gb' => 10,
            ],
            'feature_restrictions' => [
                'white_labeling',  // Only restrict white labeling
            ],
        ],
    ],
]);
```

## Starting Trials

### Basic Trial Start

```php
use LucaLongo\Licensing\Services\TrialService;

$trialService = app(TrialService::class);

// Start trial with default settings
$trial = $trialService->startTrial($license);

// Start trial with custom duration
$trial = $trialService->startTrial($license, 21); // 21 days

// Start trial with custom limitations
$trial = $trialService->startTrial($license, 14, [
    'api_calls_per_month' => 500,
    'storage_gb' => 2,
    'team_members' => 3,
]);
```

### Advanced Trial Configuration

```php
$trial = $trialService->startTrial(
    $license,
    durationDays: 14,
    limitations: [
        'api_calls_per_month' => 1000,
        'storage_gb' => 5,
        'projects' => 10,
        'exports_per_month' => 5,
    ],
    featureRestrictions: [
        'premium_support',
        'advanced_analytics',
        'custom_integrations',
        'white_labeling',
    ]
);

// Trial is automatically linked to license
assert($license->trials->contains($trial));
assert($trial->status === TrialStatus::Active);
```

### Trial with Custom Start Date

```php
// Start trial in the future
$trial = $trialService->startTrial(
    $license,
    14,
    [],
    [],
    now()->addDays(3) // Start 3 days from now
);

// Start trial with specific end date
$trial = $trialService->startTrialUntil(
    $license,
    now()->addDays(30), // End exactly 30 days from now
    $limitations,
    $featureRestrictions
);
```

## Trial Management

### Checking Trial Status

```php
// Get active trial for license
$activeTrial = $license->trials()->where('status', TrialStatus::Active)->first();

if ($activeTrial) {
    echo "Trial expires at: " . $activeTrial->ends_at;
    echo "Days remaining: " . $activeTrial->daysRemaining();
    echo "Is expired: " . ($activeTrial->isExpired() ? 'Yes' : 'No');
}

// Check if license has ever had a trial
$hasTrialed = $license->trials()->exists();

// Get trial history
$trialHistory = $license->trials()->orderBy('created_at', 'desc')->get();
```

### Trial State Transitions

```php
// Check if trial can be extended
if ($trial->canBeExtended()) {
    $trialService->extendTrial($trial, 7); // Add 7 more days
}

// Convert trial to paid license
if ($trial->isActive()) {
    $trialService->convertTrial($trial);
    assert($trial->status === TrialStatus::Converted);
    assert($license->status === LicenseStatus::Active);
}

// Cancel active trial
if ($trial->isActive()) {
    $trial->cancel();
    assert($trial->status === TrialStatus::Cancelled);
}
```

### Automated Trial Processing

Set up scheduled jobs to process trial expirations:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->job(new CheckExpiredTrialsJob)->daily();
}

// Job implementation
class CheckExpiredTrialsJob
{
    public function handle()
    {
        $expiredTrials = LicenseTrial::where('status', TrialStatus::Active)
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($expiredTrials as $trial) {
            $trial->update(['status' => TrialStatus::Expired]);
            event(new TrialExpired($trial->license, $trial));
        }
    }
}
```

## Feature Restrictions

### Implementing Feature Gates

```php
class TrialFeatureGate
{
    public function __construct(private License $license)
    {
    }

    public function allows(string $feature): bool
    {
        $activeTrial = $this->license->trials()
            ->where('status', TrialStatus::Active)
            ->first();

        if (!$activeTrial) {
            // No active trial, check full license
            return $this->license->hasFeature($feature);
        }

        // Check if feature is restricted during trial
        $restrictions = $activeTrial->feature_restrictions ?? [];
        
        if (in_array($feature, $restrictions)) {
            return false;
        }

        return $this->license->hasFeature($feature);
    }

    public function denies(string $feature): bool
    {
        return !$this->allows($feature);
    }
}

// Usage in application
$gate = new TrialFeatureGate($license);

if ($gate->allows('premium_support')) {
    // Show premium support options
} else {
    // Show upgrade prompt
}
```

### Feature Restriction Examples

```php
// API access restriction
Route::middleware(['auth', 'license'])->group(function () {
    Route::get('/api/data', function (Request $request) {
        $gate = new TrialFeatureGate($request->user()->license);
        
        if ($gate->denies('api_access')) {
            return response()->json([
                'error' => 'API access requires paid license',
                'upgrade_url' => route('upgrade'),
            ], 403);
        }
        
        return DataService::getData();
    });
});

// UI feature hiding
@php($gate = new TrialFeatureGate($license))

@if($gate->allows('advanced_analytics'))
    <div class="analytics-dashboard">
        <!-- Advanced analytics UI -->
    </div>
@else
    <div class="upgrade-prompt">
        <h3>Advanced Analytics</h3>
        <p>Unlock detailed insights with a paid plan.</p>
        <a href="{{ route('upgrade') }}" class="btn btn-primary">Upgrade Now</a>
    </div>
@endif
```

## Usage Limitations

### Implementing Usage Limits

```php
class TrialLimitationChecker
{
    public function checkLimit(License $license, string $limitKey, int $currentUsage): bool
    {
        $activeTrial = $license->trials()
            ->where('status', TrialStatus::Active)
            ->first();

        if (!$activeTrial) {
            // No trial, use full license entitlements
            $limit = $license->getEntitlement($limitKey);
        } else {
            // Use trial limitations
            $limitations = $activeTrial->limitations ?? [];
            $limit = $limitations[$limitKey] ?? null;
        }

        // No limit or unlimited (-1)
        if ($limit === null || $limit === -1) {
            return true;
        }

        return $currentUsage < $limit;
    }

    public function getRemainingUsage(License $license, string $limitKey, int $currentUsage): ?int
    {
        $activeTrial = $license->trials()
            ->where('status', TrialStatus::Active)
            ->first();

        if (!$activeTrial) {
            $limit = $license->getEntitlement($limitKey);
        } else {
            $limitations = $activeTrial->limitations ?? [];
            $limit = $limitations[$limitKey] ?? null;
        }

        if ($limit === null || $limit === -1) {
            return null; // Unlimited
        }

        return max(0, $limit - $currentUsage);
    }
}
```

### Usage Tracking Examples

```php
// API call limiting
class ApiCallMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $license = $request->user()->license;
        $checker = new TrialLimitationChecker();
        
        // Get current month's API calls
        $currentCalls = ApiCallLog::where('license_id', $license->id)
            ->whereMonth('created_at', now()->month)
            ->count();

        if (!$checker->checkLimit($license, 'api_calls_per_month', $currentCalls)) {
            return response()->json([
                'error' => 'API call limit exceeded',
                'current_calls' => $currentCalls,
                'limit' => $checker->getLimit($license, 'api_calls_per_month'),
                'upgrade_url' => route('upgrade'),
            ], 429);
        }

        // Log API call
        ApiCallLog::create([
            'license_id' => $license->id,
            'endpoint' => $request->path(),
            'method' => $request->method(),
        ]);

        return $next($request);
    }
}

// Storage limiting
class StorageService
{
    public function uploadFile(License $license, UploadedFile $file): bool
    {
        $checker = new TrialLimitationChecker();
        
        // Calculate current storage usage
        $currentStorageBytes = FileStorage::where('license_id', $license->id)->sum('size_bytes');
        $currentStorageGB = $currentStorageBytes / (1024 * 1024 * 1024);
        
        // Check if upload would exceed limit
        $fileSizeGB = $file->getSize() / (1024 * 1024 * 1024);
        $newTotal = $currentStorageGB + $fileSizeGB;
        
        if (!$checker->checkLimit($license, 'storage_gb', $newTotal)) {
            throw new StorageLimitExceededException(
                "Upload would exceed storage limit. Current: {$currentStorageGB}GB"
            );
        }
        
        // Process upload
        $path = $file->store('uploads');
        
        FileStorage::create([
            'license_id' => $license->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'size_bytes' => $file->getSize(),
        ]);
        
        return true;
    }
}
```

## Trial Extensions

### Extension Policies

```php
class TrialExtensionService
{
    public function canExtendTrial(LicenseTrial $trial): bool
    {
        // Check if extensions are globally enabled
        if (!config('licensing.trials.allow_extensions')) {
            return false;
        }

        // Check if trial is active
        if (!$trial->isActive()) {
            return false;
        }

        // Check if trial has already been extended
        if ($trial->hasBeenExtended()) {
            return false;
        }

        // Check if extension would exceed maximum days
        $maxExtensionDays = config('licensing.trials.max_extension_days');
        $currentDuration = $trial->starts_at->diffInDays($trial->ends_at);
        
        return $currentDuration < $maxExtensionDays;
    }

    public function extendTrial(LicenseTrial $trial, int $additionalDays): LicenseTrial
    {
        if (!$this->canExtendTrial($trial)) {
            throw new TrialExtensionNotAllowedException();
        }

        $maxExtensionDays = config('licensing.trials.max_extension_days');
        
        if ($additionalDays > $maxExtensionDays) {
            throw new InvalidTrialExtensionException(
                "Extension cannot exceed {$maxExtensionDays} days"
            );
        }

        $newEndDate = $trial->ends_at->addDays($additionalDays);
        
        $trial->update([
            'ends_at' => $newEndDate,
            'meta' => array_merge($trial->meta ?? [], [
                'extended' => true,
                'extended_at' => now()->toISOString(),
                'additional_days' => $additionalDays,
            ]),
        ]);

        event(new TrialExtended($trial->license, $trial, $additionalDays));

        return $trial->fresh();
    }
}
```

### Self-Service Extension

```php
// Controller for trial extension requests
class TrialExtensionController
{
    public function extend(Request $request)
    {
        $user = $request->user();
        $license = $user->license;
        
        $activeTrial = $license->trials()
            ->where('status', TrialStatus::Active)
            ->first();

        if (!$activeTrial) {
            return response()->json(['error' => 'No active trial found'], 404);
        }

        $extensionService = app(TrialExtensionService::class);
        
        if (!$extensionService->canExtendTrial($activeTrial)) {
            return response()->json([
                'error' => 'Trial cannot be extended',
                'reasons' => $this->getExtensionBlockReasons($activeTrial),
            ], 403);
        }

        try {
            $extendedTrial = $extensionService->extendTrial($activeTrial, 7);
            
            return response()->json([
                'success' => true,
                'new_end_date' => $extendedTrial->ends_at->toISOString(),
                'total_days' => $extendedTrial->starts_at->diffInDays($extendedTrial->ends_at),
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    private function getExtensionBlockReasons(LicenseTrial $trial): array
    {
        $reasons = [];
        
        if (!config('licensing.trials.allow_extensions')) {
            $reasons[] = 'Extensions are disabled';
        }
        
        if ($trial->hasBeenExtended()) {
            $reasons[] = 'Trial has already been extended';
        }
        
        return $reasons;
    }
}
```

## Conversion Tracking

### Conversion Events

```php
Event::listen(TrialConverted::class, function (TrialConverted $event) {
    $trial = $event->trial;
    $license = $event->license;
    
    // Track conversion in analytics
    Analytics::track('trial_converted', [
        'trial_id' => $trial->id,
        'license_id' => $license->uid,
        'trial_duration_days' => $trial->getDurationDays(),
        'conversion_time_days' => $trial->getConversionTimeDays(),
        'plan' => $license->template?->slug,
    ]);
    
    // Update CRM
    CrmService::recordTrialConversion($trial, $license);
    
    // Send celebration email
    Mail::to($license->licensable)->send(new TrialConvertedMail($license));
});

Event::listen(TrialExpired::class, function (TrialExpired $event) {
    $trial = $event->trial;
    
    // Track missed conversion
    Analytics::track('trial_expired', [
        'trial_id' => $trial->id,
        'duration_days' => $trial->getDurationDays(),
        'was_extended' => $trial->hasBeenExtended(),
    ]);
    
    // Send re-engagement email
    Mail::to($trial->license->licensable)->send(new TrialExpiredMail($trial));
});
```

### Conversion Analytics

```php
class TrialAnalytics
{
    public function getConversionMetrics(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $totalTrials = LicenseTrial::whereBetween('created_at', [$from, $to])->count();
        $convertedTrials = LicenseTrial::whereBetween('created_at', [$from, $to])
            ->where('status', TrialStatus::Converted)
            ->count();
        
        $conversionRate = $totalTrials > 0 ? ($convertedTrials / $totalTrials) * 100 : 0;
        
        return [
            'total_trials' => $totalTrials,
            'converted_trials' => $convertedTrials,
            'conversion_rate' => round($conversionRate, 2),
            'average_conversion_time' => $this->getAverageConversionTime($from, $to),
            'conversion_by_plan' => $this->getConversionByPlan($from, $to),
        ];
    }
    
    private function getAverageConversionTime(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $conversions = LicenseTrial::whereBetween('created_at', [$from, $to])
            ->where('status', TrialStatus::Converted)
            ->whereNotNull('converted_at')
            ->get();
        
        if ($conversions->isEmpty()) {
            return 0;
        }
        
        $totalDays = $conversions->sum(function ($trial) {
            return $trial->created_at->diffInDays($trial->converted_at);
        });
        
        return $totalDays / $conversions->count();
    }
}
```

## Best Practices

### 1. Trial Duration Strategy

```php
// Different trial lengths for different segments
class TrialDurationStrategy
{
    public function getDurationForSegment(string $segment): int
    {
        return match($segment) {
            'enterprise' => 30,    // Longer evaluation period
            'small_business' => 14, // Standard duration
            'individual' => 7,     // Shorter for individual users
            default => 14,
        };
    }
    
    public function getDurationForPlan(LicenseTemplate $template): int
    {
        // More expensive plans get longer trials
        $tierLevel = $template->tier_level;
        
        return match(true) {
            $tierLevel >= 3 => 30,  // Enterprise: 30 days
            $tierLevel === 2 => 21, // Professional: 21 days
            $tierLevel === 1 => 14, // Basic: 14 days
            default => 7,           // Free tier: 7 days
        };
    }
}
```

### 2. Progressive Feature Unlocking

```php
class ProgressiveTrialFeatures
{
    public function getAvailableFeatures(LicenseTrial $trial): array
    {
        $daysPassed = $trial->starts_at->diffInDays(now());
        $baseFeatures = ['core_functionality', 'basic_reporting'];
        
        // Unlock features progressively
        if ($daysPassed >= 3) {
            $baseFeatures[] = 'advanced_reporting';
        }
        
        if ($daysPassed >= 7) {
            $baseFeatures[] = 'integrations';
        }
        
        if ($daysPassed >= 10) {
            $baseFeatures[] = 'api_access';
        }
        
        return $baseFeatures;
    }
}
```

### 3. Trial Health Monitoring

```php
class TrialHealthMonitor
{
    public function checkTrialHealth(): array
    {
        $issues = [];
        
        // Check conversion rate
        $last30Days = now()->subDays(30);
        $metrics = app(TrialAnalytics::class)->getConversionMetrics($last30Days, now());
        
        if ($metrics['conversion_rate'] < 15) {
            $issues[] = "Low conversion rate: {$metrics['conversion_rate']}%";
        }
        
        // Check trial completion rate
        $completionRate = $this->getTrialCompletionRate($last30Days);
        if ($completionRate < 50) {
            $issues[] = "Low trial completion rate: {$completionRate}%";
        }
        
        // Check for stuck trials
        $stuckTrials = LicenseTrial::where('status', TrialStatus::Active)
            ->where('ends_at', '<', now()->subHours(24))
            ->count();
            
        if ($stuckTrials > 0) {
            $issues[] = "{$stuckTrials} trials not properly expired";
        }
        
        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => $metrics,
        ];
    }
}
```

The trial system provides powerful tools for customer acquisition and conversion, with flexible configuration options and comprehensive tracking capabilities.