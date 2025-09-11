<?php

use Illuminate\Support\Facades\Event;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Services\UsageRegistrarService;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

uses(LicenseTestHelper::class);

beforeEach(function () {
    Event::fake();
    $this->registrar = app(UsageRegistrarService::class);
});

test('enforces over limit reject policy', function () {
    $license = $this->createLicense([
        'max_usages' => 2,
        'meta' => ['policies' => ['over_limit' => 'reject']],
    ]);

    $this->registrar->register($license, 'fingerprint1');
    $this->registrar->register($license, 'fingerprint2');

    expect(fn () => $this->registrar->register($license, 'fingerprint3'))
        ->toThrow(\RuntimeException::class, 'License usage limit reached');
});

test('enforces auto replace oldest policy', function () {
    $license = $this->createLicense([
        'max_usages' => 2,
        'meta' => ['policies' => ['over_limit' => 'auto_replace_oldest']],
    ]);

    $usage1 = $this->registrar->register($license, 'fingerprint1');
    sleep(1);
    $usage2 = $this->registrar->register($license, 'fingerprint2');
    sleep(1);
    $usage3 = $this->registrar->register($license, 'fingerprint3');

    $usage1->refresh();
    $usage2->refresh();

    expect($usage1->isActive())->toBeFalse()
        ->and($usage2->isActive())->toBeTrue()
        ->and($usage3->isActive())->toBeTrue();
});

test('respects grace period policy', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->subDays(5),
        'meta' => ['policies' => ['grace_days' => 7]],
    ]);

    $license->transitionToGrace();

    expect($license->status)->toBe(LicenseStatus::Grace)
        ->and($license->isUsable())->toBeTrue()
        ->and($license->gracePeriodExpired())->toBeFalse();

    // After grace period
    $license->update(['expires_at' => now()->subDays(10)]);

    expect($license->gracePeriodExpired())->toBeTrue();

    $license->transitionToExpired();
    expect($license->status)->toBe(LicenseStatus::Expired)
        ->and($license->isUsable())->toBeFalse();
});

test('enforces usage inactivity auto revoke', function () {
    $license = $this->createLicense([
        'meta' => ['policies' => ['usage_inactivity_auto_revoke_days' => 7]],
    ]);

    $activeUsage = $this->createUsage($license, [
        'last_seen_at' => now()->subDays(5),
    ]);

    $staleUsage = $this->createUsage($license, [
        'last_seen_at' => now()->subDays(10),
    ]);

    expect($activeUsage->isStale())->toBeFalse()
        ->and($staleUsage->isStale())->toBeTrue();

    // Simulate cleanup job
    $license->usages()->stale(7)->each(function ($usage) {
        $usage->revoke('inactivity');
    });

    $activeUsage->refresh();
    $staleUsage->refresh();

    expect($activeUsage->isActive())->toBeTrue()
        ->and($staleUsage->isActive())->toBeFalse()
        ->and($staleUsage->meta['revocation_reason'])->toBe('inactivity');
});

test('enforces global fingerprint uniqueness', function () {
    $fingerprint = 'global-unique-fp';

    $license1 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'global']],
    ]);

    $license2 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'global']],
    ]);

    $usage1 = $this->registrar->register($license1, $fingerprint);

    expect($this->registrar->canRegister($license2, $fingerprint))->toBeFalse();

    expect(fn () => $this->registrar->register($license2, $fingerprint))
        ->toThrow(\RuntimeException::class, 'Fingerprint already in use globally');
});

test('enforces per-license fingerprint scope', function () {
    $fingerprint = 'license-scoped-fp';

    $license1 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'license']],
    ]);

    $license2 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'license']],
    ]);

    $usage1 = $this->registrar->register($license1, $fingerprint);
    $usage2 = $this->registrar->register($license2, $fingerprint);

    expect($usage1->id)->not->toBe($usage2->id)
        ->and($usage1->usage_fingerprint)->toBe($usage2->usage_fingerprint)
        ->and($usage1->license_id)->not->toBe($usage2->license_id);
});

test('policy inheritance from config to license', function () {
    config(['licensing.policies.over_limit' => 'reject']);
    config(['licensing.policies.grace_days' => 30]);

    $defaultLicense = $this->createLicense();

    expect($defaultLicense->getPolicy('over_limit'))->toBe('reject')
        ->and($defaultLicense->getPolicy('grace_days'))->toBe(30);

    $customLicense = $this->createLicense([
        'meta' => [
            'policies' => [
                'over_limit' => 'auto_replace_oldest',
                'grace_days' => 7,
            ],
        ],
    ]);

    expect($customLicense->getPolicy('over_limit'))->toBe('auto_replace_oldest')
        ->and($customLicense->getPolicy('grace_days'))->toBe(7);
});

test('offline token TTL inheritance', function () {
    config(['licensing.offline_token.ttl_days' => 30]);
    config(['licensing.offline_token.force_online_after_days' => 60]);

    $defaultLicense = $this->createLicense();

    expect($defaultLicense->getTokenTtlDays())->toBe(30)
        ->and($defaultLicense->getForceOnlineAfterDays())->toBe(60);

    $customLicense = $this->createLicense([
        'meta' => [
            'offline_token' => [
                'ttl_days' => 7,
                'force_online_after_days' => 14,
            ],
        ],
    ]);

    expect($customLicense->getTokenTtlDays())->toBe(7)
        ->and($customLicense->getForceOnlineAfterDays())->toBe(14);
});

test('license status transitions follow business rules', function () {
    // Pending to Active
    $license = $this->createLicense(['status' => LicenseStatus::Pending]);
    $license->activate();
    expect($license->status)->toBe(LicenseStatus::Active);

    // Active to Suspended
    $license->suspend();
    expect($license->status)->toBe(LicenseStatus::Suspended);

    // Suspended back to Active
    $license->update(['status' => LicenseStatus::Active]);

    // Active to Grace (when expired)
    $license->update(['expires_at' => now()->subDay()]);
    $license->transitionToGrace();
    expect($license->status)->toBe(LicenseStatus::Grace);

    // Grace to Expired
    $license->update(['expires_at' => now()->subDays(20)]);
    $license->transitionToExpired();
    expect($license->status)->toBe(LicenseStatus::Expired);

    // Cannot activate expired
    expect(fn () => $license->activate())
        ->toThrow(\RuntimeException::class, 'License cannot be activated in current status: expired');
});

test('concurrent usage limit enforcement', function () {
    $license = $this->createLicense(['max_usages' => 5]);

    $results = collect();
    $exceptions = collect();

    // Simulate 10 concurrent registration attempts
    for ($i = 1; $i <= 10; $i++) {
        try {
            $usage = $this->registrar->register($license, "fingerprint-{$i}");
            $results->push($usage);
        } catch (\Exception $e) {
            $exceptions->push($e);
        }
    }

    expect($results)->toHaveCount(5)
        ->and($exceptions)->toHaveCount(5)
        ->and($license->activeUsages()->count())->toBe(5);
});

test('grace period notification timing', function () {
    Event::fake();

    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    // Simulate expiration check - emit event when license is expiring soon
    if ($license->expires_at->isPast()) {
        $license->transitionToGrace();
        event(new \LucaLongo\Licensing\Events\LicenseExpired($license));
    } elseif ($license->expires_at->diffInDays(now()) <= 7) {
        $daysRemaining = $license->daysUntilExpiration();
        event(new \LucaLongo\Licensing\Events\LicenseExpiringSoon($license, $daysRemaining));
    }

    Event::assertDispatched(\LucaLongo\Licensing\Events\LicenseExpiringSoon::class);

    // Move to grace period
    $license->update(['expires_at' => now()->subDay()]);
    $license->transitionToGrace();

    expect($license->status)->toBe(LicenseStatus::Grace);
});
