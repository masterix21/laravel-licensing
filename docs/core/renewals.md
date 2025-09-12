# Renewals

The Renewal system tracks license extension periods, managing the lifecycle of licenses through multiple renewal cycles. This document covers renewal creation, tracking, billing integration, and renewal workflows.

## Table of Contents

- [Overview](#overview)
- [Renewal Model](#renewal-model)
- [Renewal Process](#renewal-process)
- [Renewal Types](#renewal-types)
- [Billing Integration](#billing-integration)
- [Automatic Renewals](#automatic-renewals)
- [Renewal Analytics](#renewal-analytics)
- [Renewal Notifications](#renewal-notifications)
- [Best Practices](#best-practices)

## Overview

License renewals extend the validity period of existing licenses. Each renewal creates a historical record of the extension, including the period covered, pricing information, and any additional notes.

```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseRenewal;

// Renew a license for another year
$license->renew(
    now()->addYear(),
    [
        'amount_cents' => 9999, // $99.99
        'currency' => 'USD',
        'notes' => 'Annual renewal - Professional Plan'
    ]
);

// The renewal record is automatically created
$renewal = $license->renewals()->latest()->first();
echo "Renewed from {$renewal->period_start} to {$renewal->period_end}";
```

## Renewal Model

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | ULID | Primary key |
| `license_id` | ULID | Associated license |
| `period_start` | Date | Start of the renewal period |
| `period_end` | Date | End of the renewal period |
| `amount_cents` | Integer | Renewal amount in cents (nullable) |
| `currency` | String | Currency code (nullable) |
| `notes` | String | Additional notes about the renewal |
| `created_at` | DateTime | When renewal was processed |
| `updated_at` | DateTime | Last modification time |

### Renewal Record Structure

```php
$renewal = LicenseRenewal::create([
    'license_id' => $license->id,
    'period_start' => now(), // Usually the old expires_at
    'period_end' => now()->addYear(), // New expires_at
    'amount_cents' => 12999, // $129.99
    'currency' => 'USD',
    'notes' => 'Annual renewal with 15% discount',
]);

// Helper methods
echo $renewal->getDurationInDays(); // 365
echo $renewal->getFormattedAmount(); // "129.99 USD"
```

### Relationships

```php
// Renewal belongs to a license
$renewal->license;

// License has many renewals
$license->renewals;
$license->renewals()->latest()->first(); // Most recent renewal
```

## Renewal Process

### Basic Renewal

```php
// Simple renewal extending for one year
$license->renew(now()->addYear());

// Renewal with billing information
$license->renew(
    now()->addYear(),
    [
        'amount_cents' => 19999,
        'currency' => 'USD',
        'notes' => 'Upgraded to Professional Plan'
    ]
);

// Custom period renewal
$license->renew(
    now()->addMonths(6),
    [
        'amount_cents' => 4999,
        'currency' => 'USD',
        'notes' => '6-month renewal'
    ]
);
```

### Renewal Validation

```php
// Check if license can be renewed
if (!$license->status->canRenew()) {
    throw new \RuntimeException(
        'License cannot be renewed in current status: ' . $license->status->value
    );
}

// Renewal service with validation
class LicenseRenewalService
{
    public function renew(License $license, \DateTimeInterface $expiresAt, array $data = []): LicenseRenewal
    {
        // Validate license state
        if (!$license->status->canRenew()) {
            throw new LicenseNotRenewableException($license);
        }
        
        // Validate renewal period
        if ($expiresAt <= ($license->expires_at ?? now())) {
            throw new InvalidRenewalPeriodException('Renewal period must be in the future');
        }
        
        return DB::transaction(function() use ($license, $expiresAt, $data) {
            $oldExpiresAt = $license->expires_at;
            
            // Update license expiration
            $license->update([
                'expires_at' => $expiresAt,
                'status' => LicenseStatus::Active,
            ]);
            
            // Create renewal record
            return $license->renewals()->create(array_merge([
                'period_start' => $oldExpiresAt ?? now(),
                'period_end' => $expiresAt,
            ], $data));
        });
    }
}
```

### Renewal History Tracking

```php
// Get complete renewal history
$renewalHistory = $license->renewals()
    ->orderBy('created_at', 'desc')
    ->get()
    ->map(function($renewal) {
        return [
            'period' => "{$renewal->period_start->format('Y-m-d')} to {$renewal->period_end->format('Y-m-d')}",
            'duration' => $renewal->getDurationInDays() . ' days',
            'amount' => $renewal->getFormattedAmount(),
            'renewed_at' => $renewal->created_at,
            'notes' => $renewal->notes,
        ];
    });

// Calculate total renewal value
$totalRenewalValue = $license->renewals()
    ->whereNotNull('amount_cents')
    ->sum('amount_cents');

// Average renewal amount
$averageRenewal = $license->renewals()
    ->whereNotNull('amount_cents')
    ->avg('amount_cents');
```

## Renewal Types

### Standard Renewals

```php
// Annual renewal (most common)
$license->renew(
    $license->expires_at->addYear(),
    [
        'amount_cents' => 9999,
        'currency' => 'USD',
        'notes' => 'Annual renewal'
    ]
);

// Monthly renewal
$license->renew(
    $license->expires_at->addMonth(),
    [
        'amount_cents' => 999,
        'currency' => 'USD', 
        'notes' => 'Monthly renewal'
    ]
);
```

### Grace Period Renewals

```php
// Renew license in grace period
if ($license->isInGracePeriod()) {
    $license->renew(
        now()->addYear(), // Extends from now, not from original expiry
        [
            'amount_cents' => 9999,
            'currency' => 'USD',
            'notes' => 'Renewal during grace period'
        ]
    );
}
```

### Expired License Renewals

```php
// Renew expired license (reactivation)
if ($license->status === LicenseStatus::Expired) {
    $license->renew(
        now()->addYear(),
        [
            'amount_cents' => 9999,
            'currency' => 'USD',
            'notes' => 'License reactivation after expiration'
        ]
    );
    
    // License status automatically changes to Active
    assert($license->status === LicenseStatus::Active);
}
```

### Upgrade Renewals

```php
// Renew with plan upgrade
$newTemplate = LicenseTemplate::findBySlug('professional-plan');

$license->renew(
    now()->addYear(),
    [
        'amount_cents' => 19999, // Higher price for upgraded plan
        'currency' => 'USD',
        'notes' => 'Upgraded from Basic to Professional'
    ]
);

// Update license template
$license->update(['template_id' => $newTemplate->id]);

// Update license configuration from new template
$license->meta = array_merge(
    $license->meta ?? [],
    $newTemplate->resolveConfiguration()
);
```

## Billing Integration

### Renewal with Payment Processing

```php
class RenewalPaymentService
{
    public function processRenewal(License $license, array $paymentData): array
    {
        // Calculate renewal amount
        $amount = $this->calculateRenewalAmount($license);
        
        // Process payment
        $paymentResult = $this->processPayment($paymentData, $amount);
        
        if ($paymentResult['success']) {
            // Create renewal record
            $renewal = $license->renew(
                $this->calculateRenewalPeriod($license),
                [
                    'amount_cents' => $amount,
                    'currency' => $paymentData['currency'],
                    'notes' => "Payment ID: {$paymentResult['payment_id']}"
                ]
            );
            
            return [
                'success' => true,
                'renewal' => $renewal,
                'payment_id' => $paymentResult['payment_id'],
            ];
        }
        
        return [
            'success' => false,
            'error' => $paymentResult['error'],
        ];
    }
    
    private function calculateRenewalAmount(License $license): int
    {
        // Base price from template
        $baseAmount = $license->template?->meta['pricing']['annually'] ?? 9999;
        
        // Apply discounts, taxes, etc.
        $discount = $this->calculateRenewalDiscount($license);
        $tax = $this->calculateTax($baseAmount, $license->licensable);
        
        return $baseAmount - $discount + $tax;
    }
    
    private function calculateRenewalDiscount(License $license): int
    {
        $renewalCount = $license->renewals()->count();
        
        // Loyalty discount: 5% per renewal, max 25%
        $discountPercent = min($renewalCount * 5, 25);
        
        return (int) ($license->template?->meta['pricing']['annually'] * ($discountPercent / 100));
    }
}
```

### Subscription Integration

```php
class SubscriptionRenewalService
{
    public function handleSubscriptionRenewal(array $webhookData): void
    {
        $subscriptionId = $webhookData['subscription_id'];
        $license = License::where('meta->subscription_id', $subscriptionId)->first();
        
        if (!$license) {
            throw new \Exception("License not found for subscription: {$subscriptionId}");
        }
        
        // Process renewal from subscription data
        $license->renew(
            Carbon::parse($webhookData['current_period_end']),
            [
                'amount_cents' => $webhookData['amount_paid'],
                'currency' => strtoupper($webhookData['currency']),
                'notes' => "Automatic subscription renewal - Invoice: {$webhookData['invoice_id']}"
            ]
        );
        
        // Update subscription metadata
        $license->meta = array_merge($license->meta ?? [], [
            'subscription_status' => $webhookData['status'],
            'last_invoice_id' => $webhookData['invoice_id'],
            'renewed_at' => now()->toISOString(),
        ]);
        
        $license->save();
    }
}
```

## Automatic Renewals

### Renewal Notifications

```php
// Check for licenses expiring soon
$expiringSoon = License::where('status', LicenseStatus::Active)
    ->whereBetween('expires_at', [now(), now()->addDays(30)])
    ->whereDoesntHave('renewals', function($query) {
        $query->where('period_start', '>', now());
    })
    ->get();

foreach ($expiringSoon as $license) {
    event(new LicenseExpiringSoon($license));
}
```

### Scheduled Renewal Processing

```php
class ProcessScheduledRenewalsJob
{
    public function handle()
    {
        // Find licenses with automatic renewal enabled
        $autoRenewLicenses = License::where('status', LicenseStatus::Active)
            ->where('expires_at', '<=', now()->addDays(3))
            ->where('meta->auto_renew', true)
            ->get();
        
        foreach ($autoRenewLicenses as $license) {
            try {
                $this->processAutoRenewal($license);
            } catch (\Exception $e) {
                // Log error and notify administrators
                logger('Auto-renewal failed', [
                    'license_id' => $license->id,
                    'error' => $e->getMessage(),
                ]);
                
                event(new AutoRenewalFailed($license, $e));
            }
        }
    }
    
    private function processAutoRenewal(License $license): void
    {
        $subscriptionId = $license->meta['subscription_id'] ?? null;
        
        if (!$subscriptionId) {
            throw new \Exception('No subscription ID found for auto-renewal');
        }
        
        // Process renewal through payment provider
        $paymentService = app(PaymentService::class);
        $result = $paymentService->renewSubscription($subscriptionId);
        
        if ($result['success']) {
            $license->renew(
                now()->addYear(),
                [
                    'amount_cents' => $result['amount'],
                    'currency' => $result['currency'],
                    'notes' => 'Automatic renewal - Invoice: ' . $result['invoice_id']
                ]
            );
        } else {
            throw new \Exception('Payment failed: ' . $result['error']);
        }
    }
}
```

## Renewal Analytics

### Renewal Metrics

```php
class RenewalAnalytics
{
    public function getRenewalMetrics(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $from = $from ?? now()->subYear();
        $to = $to ?? now();
        
        $renewals = LicenseRenewal::whereBetween('created_at', [$from, $to]);
        
        return [
            'total_renewals' => $renewals->count(),
            'total_revenue' => $renewals->whereNotNull('amount_cents')->sum('amount_cents'),
            'average_renewal_amount' => $renewals->whereNotNull('amount_cents')->avg('amount_cents'),
            'renewal_rate' => $this->calculateRenewalRate($from, $to),
            'monthly_recurring_revenue' => $this->calculateMRR(),
            'annual_recurring_revenue' => $this->calculateARR(),
            'churn_rate' => $this->calculateChurnRate($from, $to),
        ];
    }
    
    public function getRenewalTrends(): array
    {
        return LicenseRenewal::selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                COUNT(*) as renewal_count,
                SUM(amount_cents) as total_amount,
                AVG(amount_cents) as average_amount
            ')
            ->whereNotNull('amount_cents')
            ->where('created_at', '>=', now()->subYear())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function($row) {
                return [
                    'month' => $row->month,
                    'renewal_count' => $row->renewal_count,
                    'total_amount' => $row->total_amount / 100, // Convert to dollars
                    'average_amount' => $row->average_amount / 100,
                ];
            });
    }
    
    private function calculateRenewalRate(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        // Licenses that expired in the period
        $expiredCount = License::whereBetween('expires_at', [$from, $to])->count();
        
        // Licenses that were renewed
        $renewedCount = LicenseRenewal::whereBetween('created_at', [$from, $to])->count();
        
        return $expiredCount > 0 ? ($renewedCount / $expiredCount) * 100 : 0;
    }
    
    private function calculateMRR(): float
    {
        // Monthly renewals
        $monthlyRenewals = LicenseRenewal::where('created_at', '>=', now()->subMonth())
            ->where(function($query) {
                $query->where('period_end', '<=', DB::raw('DATE_ADD(period_start, INTERVAL 35 DAY)'))
                      ->orWhereRaw('DATEDIFF(period_end, period_start) <= 35');
            })
            ->sum('amount_cents');
        
        return $monthlyRenewals / 100;
    }
    
    private function calculateARR(): float
    {
        return $this->calculateMRR() * 12;
    }
}
```

### Customer Lifecycle Analytics

```php
class CustomerLifecycleAnalytics
{
    public function getCustomerMetrics(Model $licensable): array
    {
        $licenses = $licensable->licenses;
        $renewals = LicenseRenewal::whereIn('license_id', $licenses->pluck('id'));
        
        return [
            'total_licenses' => $licenses->count(),
            'active_licenses' => $licenses->where('status', 'active')->count(),
            'total_renewals' => $renewals->count(),
            'total_spent' => $renewals->sum('amount_cents') / 100,
            'average_renewal_amount' => $renewals->avg('amount_cents') / 100,
            'customer_lifetime_value' => $this->calculateCLV($licensable),
            'renewal_frequency' => $this->calculateRenewalFrequency($renewals),
            'last_renewal' => $renewals->latest()->first()?->created_at,
        ];
    }
    
    private function calculateCLV(Model $licensable): float
    {
        $renewals = LicenseRenewal::whereHas('license', function($query) use ($licensable) {
            $query->where('licensable_type', get_class($licensable))
                  ->where('licensable_id', $licensable->id);
        });
        
        $totalSpent = $renewals->sum('amount_cents') / 100;
        $avgRenewalAmount = $renewals->avg('amount_cents') / 100;
        $renewalCount = $renewals->count();
        
        // Simple CLV calculation
        return $totalSpent + ($avgRenewalAmount * 3); // Assume 3 more renewals
    }
}
```

## Renewal Notifications

### Email Notifications

```php
class RenewalNotificationService
{
    public function sendRenewalNotices(): void
    {
        $this->sendExpirationNotices();
        $this->sendRenewalConfirmations();
        $this->sendRenewalFailureNotices();
    }
    
    private function sendExpirationNotices(): void
    {
        // 30 days before expiration
        $expiring30Days = License::where('status', LicenseStatus::Active)
            ->whereBetween('expires_at', [now()->addDays(30), now()->addDays(31)])
            ->get();
        
        foreach ($expiring30Days as $license) {
            Mail::to($license->licensable)->send(new RenewalReminderMail($license, 30));
        }
        
        // 7 days before expiration
        $expiring7Days = License::where('status', LicenseStatus::Active)
            ->whereBetween('expires_at', [now()->addDays(7), now()->addDays(8)])
            ->get();
        
        foreach ($expiring7Days as $license) {
            Mail::to($license->licensable)->send(new RenewalReminderMail($license, 7));
        }
    }
    
    private function sendRenewalConfirmations(): void
    {
        $recentRenewals = LicenseRenewal::where('created_at', '>=', now()->subDay())
            ->with('license.licensable')
            ->get();
        
        foreach ($recentRenewals as $renewal) {
            Mail::to($renewal->license->licensable)
                ->send(new RenewalConfirmationMail($renewal));
        }
    }
}

// Example renewal reminder email
class RenewalReminderMail extends Mailable
{
    public function __construct(
        public License $license,
        public int $daysUntilExpiration
    ) {}
    
    public function build()
    {
        return $this->subject("Your license expires in {$this->daysUntilExpiration} days")
            ->markdown('emails.renewal-reminder', [
                'license' => $this->license,
                'daysUntilExpiration' => $this->daysUntilExpiration,
                'renewalUrl' => route('licenses.renew', $this->license->uid),
            ]);
    }
}
```

## Best Practices

### Renewal Strategy

1. **Clear Communication**: Send timely renewal notices
2. **Flexible Terms**: Offer multiple renewal periods
3. **Loyalty Rewards**: Provide discounts for long-term customers
4. **Grace Periods**: Allow continued usage during renewal process
5. **Payment Recovery**: Handle failed payments gracefully

### Data Management

```php
// Keep detailed renewal history
$renewal = LicenseRenewal::create([
    'license_id' => $license->id,
    'period_start' => $oldExpiration,
    'period_end' => $newExpiration,
    'amount_cents' => $amount,
    'currency' => 'USD',
    'notes' => json_encode([
        'payment_method' => 'credit_card',
        'invoice_id' => 'INV-123456',
        'discount_applied' => '10% loyalty discount',
        'processed_by' => 'auto-renewal-system',
        'renewal_type' => 'annual',
    ])
]);

// Track renewal attempts
$license->meta = array_merge($license->meta ?? [], [
    'renewal_attempts' => [
        [
            'attempted_at' => now()->toISOString(),
            'success' => true,
            'amount' => $amount,
            'method' => 'stripe',
        ]
    ]
]);
```

### Monitoring and Alerts

```php
// Monitor renewal health
class RenewalHealthMonitor
{
    public function checkRenewalHealth(): array
    {
        $issues = [];
        
        // Check for licenses expiring without renewal setup
        $expiringWithoutRenewal = License::where('status', LicenseStatus::Active)
            ->where('expires_at', '<=', now()->addDays(7))
            ->whereNull('meta->subscription_id')
            ->count();
        
        if ($expiringWithoutRenewal > 0) {
            $issues[] = "{$expiringWithoutRenewal} licenses expiring soon without renewal setup";
        }
        
        // Check renewal failure rate
        $recentRenewals = LicenseRenewal::where('created_at', '>=', now()->subMonth());
        $failedRenewals = License::where('updated_at', '>=', now()->subMonth())
            ->where('meta->renewal_failed', true)
            ->count();
        
        $failureRate = $recentRenewals->count() > 0 
            ? ($failedRenewals / $recentRenewals->count()) * 100 
            : 0;
        
        if ($failureRate > 10) {
            $issues[] = "Renewal failure rate is {$failureRate}% (threshold: 10%)";
        }
        
        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'metrics' => [
                'expiring_soon' => $expiringWithoutRenewal,
                'renewal_failure_rate' => $failureRate,
            ]
        ];
    }
}
```

The Renewal system provides comprehensive tracking of license extensions, enabling effective license lifecycle management and revenue optimization through detailed analytics and automated processes.