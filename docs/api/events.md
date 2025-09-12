# API Reference: Events

This document provides comprehensive API reference for all events in the Laravel Licensing package. Events are dispatched during various licensing operations and can be used for notifications, integrations, and custom business logic.

## Table of Contents

- [License Events](#license-events)
- [Usage Events](#usage-events)  
- [Trial Events](#trial-events)
- [Transfer Events](#transfer-events)
- [Event Listeners](#event-listeners)
- [Event Data](#event-data)
- [Custom Events](#custom-events)

## License Events

Events related to license lifecycle and state changes.

### LicenseActivated

Dispatched when a license is activated from pending state.

```php
namespace LucaLongo\Licensing\Events;

class LicenseActivated
{
    public function __construct(
        public License $license
    ) {}
}
```

**When dispatched:**
- License status changes from `pending` to `active`
- `License::activate()` method is called
- License is activated via API

**Use cases:**
- Send welcome email to customer
- Enable features in your application
- Update billing system
- Trigger integrations

**Example listener:**

```php
Event::listen(LicenseActivated::class, function (LicenseActivated $event) {
    $license = $event->license;
    $licensable = $license->licensable;
    
    // Send welcome email
    if ($licensable instanceof User) {
        Mail::to($licensable)->send(new LicenseWelcomeMail($license));
    }
    
    // Enable features
    FeatureToggle::enableForLicense($license);
    
    // Update external systems
    BillingService::activateLicense($license);
});
```

### LicenseRenewed

Dispatched when a license is renewed/extended.

```php
class LicenseRenewed
{
    public function __construct(
        public License $license,
        public LicenseRenewal $renewal
    ) {}
}
```

**When dispatched:**
- License expiration is extended
- `License::renew()` method is called
- Renewal is processed via payment

**Example listener:**

```php
Event::listen(LicenseRenewed::class, function (LicenseRenewed $event) {
    $license = $event->license;
    $renewal = $event->renewal;
    
    // Send renewal confirmation
    Mail::to($license->licensable)->send(
        new RenewalConfirmationMail($license, $renewal)
    );
    
    // Update subscription status
    SubscriptionService::updateRenewal($license, $renewal);
    
    // Analytics tracking
    Analytics::track('license_renewed', [
        'license_id' => $license->uid,
        'amount' => $renewal->amount_cents,
        'duration' => $renewal->getDurationInDays(),
    ]);
});
```

### LicenseExpired

Dispatched when a license expires (after grace period).

```php
class LicenseExpired
{
    public function __construct(
        public License $license
    ) {}
}
```

**When dispatched:**
- License transitions from `grace` to `expired`
- Grace period ends without renewal
- Scheduled job processes expired licenses

**Example listener:**

```php
Event::listen(LicenseExpired::class, function (LicenseExpired $event) {
    $license = $event->license;
    
    // Disable features
    FeatureToggle::disableForLicense($license);
    
    // Revoke all active usages
    $license->usages()->where('status', 'active')->update([
        'status' => 'revoked',
        'revoked_at' => now(),
    ]);
    
    // Send expiration notice
    Mail::to($license->licensable)->send(new LicenseExpiredMail($license));
    
    // Update external systems
    WebhookService::sendLicenseExpired($license);
});
```

### LicenseExpiringSoon

Dispatched when a license is approaching expiration.

```php
class LicenseExpiringSoon
{
    public function __construct(
        public License $license,
        public int $daysUntilExpiration
    ) {}
}
```

**When dispatched:**
- Scheduled job finds licenses expiring within configured timeframe
- Typically dispatched at 30, 14, 7, and 1 day intervals

**Example listener:**

```php
Event::listen(LicenseExpiringSoon::class, function (LicenseExpiringSoon $event) {
    $license = $event->license;
    $days = $event->daysUntilExpiration;
    
    // Send reminder email
    Mail::to($license->licensable)->send(
        new RenewalReminderMail($license, $days)
    );
    
    // Create renewal opportunity
    RenewalOpportunityService::create($license, $days);
    
    // Notify sales team for high-value licenses
    if ($license->getLastRenewalAmount() > 100000) { // $1000+
        SalesTeam::notifyExpiringLicense($license, $days);
    }
});
```

### LicenseSuspended

Dispatched when a license is suspended.

```php
class LicenseSuspended
{
    public function __construct(
        public License $license,
        public ?string $reason = null
    ) {}
}
```

### LicenseCancelled

Dispatched when a license is permanently cancelled.

```php
class LicenseCancelled
{
    public function __construct(
        public License $license,
        public ?string $reason = null
    ) {}
}
```

## Usage Events

Events related to seat/usage management.

### UsageRegistered

Dispatched when a new usage is registered (seat consumed).

```php
class UsageRegistered
{
    public function __construct(
        public License $license,
        public LicenseUsage $usage
    ) {}
}
```

**When dispatched:**
- New seat is consumed
- `UsageRegistrarService::register()` is called
- API registers new usage

**Example listener:**

```php
Event::listen(UsageRegistered::class, function (UsageRegistered $event) {
    $license = $event->license;
    $usage = $event->usage;
    
    // Track usage analytics
    Analytics::track('seat_consumed', [
        'license_id' => $license->uid,
        'client_type' => $usage->client_type,
        'seats_remaining' => $license->getAvailableSeats(),
    ]);
    
    // Notify if approaching limit
    if ($license->getAvailableSeats() <= 1) {
        NotificationService::sendLowSeatsWarning($license);
    }
    
    // Update monitoring
    MetricsService::incrementCounter('active_seats', [
        'license_type' => $license->template?->slug ?? 'unknown',
    ]);
});
```

### UsageRevoked

Dispatched when a usage is revoked (seat freed).

```php
class UsageRevoked
{
    public function __construct(
        public License $license,
        public LicenseUsage $usage,
        public ?string $reason = null
    ) {}
}
```

**When dispatched:**
- Seat is manually revoked
- Usage is auto-revoked due to inactivity
- Over-limit policy replaces usage

### UsageLimitReached

Dispatched when a license reaches its usage limit.

```php
class UsageLimitReached
{
    public function __construct(
        public License $license,
        public int $attemptedUsages,
        public string $rejectedFingerprint
    ) {}
}
```

**Example listener:**

```php
Event::listen(UsageLimitReached::class, function (UsageLimitReached $event) {
    $license = $event->license;
    
    // Suggest upgrade
    UpgradeService::suggestPlanUpgrade($license);
    
    // Notify license administrator
    Mail::to($license->licensable)->send(
        new SeatLimitReachedMail($license)
    );
    
    // Track conversion opportunity
    ConversionTracker::recordUpgradeOpportunity($license, 'seat_limit');
});
```

## Trial Events

Events related to trial period management.

### TrialStarted

Dispatched when a trial period begins.

```php
class TrialStarted
{
    public function __construct(
        public License $license,
        public LicenseTrial $trial
    ) {}
}
```

**Example listener:**

```php
Event::listen(TrialStarted::class, function (TrialStarted $event) {
    $license = $event->license;
    $trial = $event->trial;
    
    // Send trial welcome email
    Mail::to($license->licensable)->send(
        new TrialWelcomeMail($license, $trial)
    );
    
    // Schedule conversion emails
    TrialNurtureService::scheduleEmails($trial);
    
    // Enable trial features
    FeatureToggle::enableTrialFeatures($license, $trial);
});
```

### TrialExtended

Dispatched when a trial period is extended.

```php
class TrialExtended
{
    public function __construct(
        public License $license,
        public LicenseTrial $trial,
        public int $additionalDays
    ) {}
}
```

### TrialConverted

Dispatched when a trial is converted to a paid license.

```php
class TrialConverted
{
    public function __construct(
        public License $license,
        public LicenseTrial $trial
    ) {}
}
```

**Example listener:**

```php
Event::listen(TrialConverted::class, function (TrialConverted $event) {
    $license = $event->license;
    $trial = $event->trial;
    
    // Send conversion welcome
    Mail::to($license->licensable)->send(
        new TrialConvertedMail($license)
    );
    
    // Enable full features
    FeatureToggle::enableAllFeatures($license);
    
    // Update sales attribution
    SalesAttribution::recordConversion($trial, $license);
    
    // Celebrate with team
    SlackService::notifyTrialConversion($license);
});
```

### TrialExpired

Dispatched when a trial period expires without conversion.

```php
class TrialExpired
{
    public function __construct(
        public License $license,
        public LicenseTrial $trial
    ) {}
}
```

## Transfer Events

Events related to license transfers and ownership changes.

### LicenseTransferInitiated

Dispatched when a license transfer is initiated.

```php
class LicenseTransferInitiated
{
    public function __construct(
        public LicenseTransfer $transfer
    ) {}
}
```

**Example listener:**

```php
Event::listen(LicenseTransferInitiated::class, function (LicenseTransferInitiated $event) {
    $transfer = $event->transfer;
    
    // Notify current owner
    Mail::to($transfer->initiator)->send(
        new TransferInitiatedMail($transfer)
    );
    
    // Notify recipient
    Mail::to($transfer->recipient)->send(
        new TransferRequestMail($transfer)
    );
    
    // Create approval workflow if needed
    if ($transfer->requiresApproval()) {
        ApprovalWorkflow::create($transfer);
    }
});
```

### LicenseTransferCompleted

Dispatched when a license transfer is completed.

```php
class LicenseTransferCompleted
{
    public function __construct(
        public LicenseTransfer $transfer,
        public LicenseTransferHistory $history
    ) {}
}
```

### LicenseTransferRejected

Dispatched when a license transfer is rejected.

```php
class LicenseTransferRejected
{
    public function __construct(
        public LicenseTransfer $transfer,
        public ?string $reason = null
    ) {}
}
```

## Event Listeners

### Creating Listeners

Generate event listeners using Artisan:

```bash
# Create a listener for a specific event
php artisan make:listener SendLicenseWelcomeEmail --event=LicenseActivated

# Create a queued listener
php artisan make:listener ProcessLicenseExpiration --event=LicenseExpired --queued
```

### Listener Implementation

```php
class SendLicenseWelcomeEmail
{
    public function handle(LicenseActivated $event): void
    {
        $license = $event->license;
        $licensable = $license->licensable;
        
        // Only send email to User models
        if (!$licensable instanceof User) {
            return;
        }
        
        // Check if welcome email should be sent
        if (!$this->shouldSendWelcomeEmail($license)) {
            return;
        }
        
        // Send the email
        Mail::to($licensable)->send(new LicenseWelcomeMail($license));
        
        // Mark as sent
        $license->meta = array_merge($license->meta ?? [], [
            'welcome_email_sent' => true,
            'welcome_email_sent_at' => now()->toISOString(),
        ]);
        $license->save();
    }
    
    private function shouldSendWelcomeEmail(License $license): bool
    {
        return !($license->meta['welcome_email_sent'] ?? false);
    }
}
```

### Queued Listeners

For heavy processing or external API calls:

```php
class ProcessLicenseExpiration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function handle(LicenseExpired $event): void
    {
        $license = $event->license;
        
        // Heavy operations that should be queued
        $this->disableFeatures($license);
        $this->updateExternalSystems($license);
        $this->generateAnalyticsReport($license);
    }
    
    private function disableFeatures(License $license): void
    {
        // Bulk disable features across multiple services
        FeatureService::bulkDisable($license);
        ApiService::revokeTokens($license);
        IntegrationService::disableWebhooks($license);
    }
}
```

### Registering Listeners

In your `EventServiceProvider`:

```php
protected $listen = [
    \LucaLongo\Licensing\Events\LicenseActivated::class => [
        \App\Listeners\SendLicenseWelcomeEmail::class,
        \App\Listeners\EnableFeatures::class,
        \App\Listeners\UpdateBillingSystem::class,
    ],
    
    \LucaLongo\Licensing\Events\LicenseExpired::class => [
        \App\Listeners\ProcessLicenseExpiration::class,
        \App\Listeners\NotifyCustomerService::class,
    ],
    
    \LucaLongo\Licensing\Events\TrialConverted::class => [
        \App\Listeners\CelebrateConversion::class,
        \App\Listeners\UpdateSalesMetrics::class,
    ],
];
```

## Event Data

### Accessing Event Data

All events provide access to relevant models and context:

```php
Event::listen(UsageRegistered::class, function (UsageRegistered $event) {
    // Access the license
    $license = $event->license;
    echo "License ID: " . $license->id;
    echo "License Status: " . $license->status->value;
    echo "Available Seats: " . $license->getAvailableSeats();
    
    // Access the usage
    $usage = $event->usage;
    echo "Usage Fingerprint: " . $usage->usage_fingerprint;
    echo "Client Type: " . $usage->client_type;
    echo "Name: " . $usage->name;
    
    // Access licensable (User, Organization, etc.)
    $licensable = $license->licensable;
    if ($licensable instanceof User) {
        echo "User Email: " . $licensable->email;
    }
});
```

### Event Context

Some events include additional context:

```php
Event::listen(LicenseExpiringSoon::class, function (LicenseExpiringSoon $event) {
    $license = $event->license;
    $days = $event->daysUntilExpiration;
    
    // Different actions based on days remaining
    match ($days) {
        30 => $this->sendFirstReminder($license),
        14 => $this->sendSecondReminder($license),
        7 => $this->sendUrgentReminder($license),
        1 => $this->sendFinalReminder($license),
        default => null,
    };
});
```

## Custom Events

### Creating Custom Events

You can dispatch custom events for your specific use cases:

```php
namespace App\Events\Licensing;

use LucaLongo\Licensing\Models\License;

class LicenseUpgraded
{
    public function __construct(
        public License $license,
        public string $fromPlan,
        public string $toPlan,
        public int $additionalRevenue
    ) {}
}

// Dispatch the event
event(new LicenseUpgraded($license, 'basic', 'premium', 5000));
```

### Event Subscribers

For complex event handling, use event subscribers:

```php
class LicenseEventSubscriber
{
    public function subscribe($events): void
    {
        $events->listen(
            LicenseActivated::class,
            [LicenseEventSubscriber::class, 'onLicenseActivated']
        );
        
        $events->listen(
            LicenseExpired::class,
            [LicenseEventSubscriber::class, 'onLicenseExpired']
        );
        
        $events->listen(
            TrialConverted::class,
            [LicenseEventSubscriber::class, 'onTrialConverted']
        );
    }
    
    public function onLicenseActivated(LicenseActivated $event): void
    {
        // Handle license activation
    }
    
    public function onLicenseExpired(LicenseExpired $event): void
    {
        // Handle license expiration
    }
    
    public function onTrialConverted(TrialConverted $event): void
    {
        // Handle trial conversion
    }
}

// Register in EventServiceProvider
protected $subscribe = [
    \App\Listeners\LicenseEventSubscriber::class,
];
```

### Testing Events

```php
use Illuminate\Support\Facades\Event;

class LicenseTest extends TestCase
{
    public function test_license_activation_dispatches_event()
    {
        Event::fake();
        
        $license = License::factory()->create(['status' => 'pending']);
        
        $license->activate();
        
        Event::assertDispatched(LicenseActivated::class, function ($event) use ($license) {
            return $event->license->id === $license->id;
        });
    }
    
    public function test_usage_registration_dispatches_event()
    {
        Event::fake();
        
        $license = License::factory()->active()->create();
        $registrar = app(UsageRegistrarService::class);
        
        $usage = $registrar->register($license, [
            'usage_fingerprint' => 'test-fingerprint',
            'client_type' => 'test-client',
        ]);
        
        Event::assertDispatched(UsageRegistered::class);
    }
}
```

## Event Best Practices

### 1. Keep Events Simple

Events should be simple data containers:

```php
// Good: Simple event with essential data
class LicenseActivated
{
    public function __construct(public License $license) {}
}

// Avoid: Complex logic in events
class LicenseActivated
{
    public function __construct(public License $license)
    {
        // Don't do heavy processing here
        $this->sendNotifications();
        $this->updateAnalytics();
    }
}
```

### 2. Use Queued Listeners for Heavy Work

```php
// Queue heavy operations
class UpdateAnalytics implements ShouldQueue
{
    public function handle(LicenseActivated $event): void
    {
        // Heavy analytics processing
        AnalyticsService::processLicenseActivation($event->license);
    }
}
```

### 3. Handle Failures Gracefully

```php
class SendWelcomeEmail
{
    public function handle(LicenseActivated $event): void
    {
        try {
            Mail::to($event->license->licensable)->send(new WelcomeEmail());
        } catch (Exception $e) {
            // Log error but don't fail the entire process
            logger()->error('Failed to send welcome email', [
                'license_id' => $event->license->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### 4. Make Events Testable

Use Event::fake() in tests and assert expected events are dispatched:

```php
Event::fake();

// Perform action
$license->activate();

// Assert event was dispatched
Event::assertDispatched(LicenseActivated::class);
```

This comprehensive event system allows you to build reactive applications that respond to licensing changes with custom business logic, notifications, and integrations.