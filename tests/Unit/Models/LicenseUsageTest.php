<?php

use Illuminate\Support\Facades\Event;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Events\UsageRevoked;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;
use function Spatie\PestPluginTestTime\testTime;

uses(LicenseTestHelper::class);

beforeEach(function () {
    Event::fake();
    $this->license = $this->createLicense();
});

test('can create usage with auto timestamps', function () {
    $usage = $this->createUsage($this->license);

    expect($usage)->toBeInstanceOf(LicenseUsage::class)
        ->and($usage->status)->toBe(UsageStatus::Active)
        ->and($usage->registered_at)->not->toBeNull()
        ->and($usage->last_seen_at)->not->toBeNull();
});

test('can update heartbeat', function () {
    testTime()->freeze();
    $usage = $this->createUsage($this->license);
    $originalTime = $usage->last_seen_at;

    testTime()->addSeconds(5);
    $usage->heartbeat();

    expect($usage->last_seen_at)->toBeGreaterThan($originalTime);
});

test('can revoke usage', function () {
    $usage = $this->createUsage($this->license);

    $usage->revoke('test reason');

    expect($usage->status)->toBe(UsageStatus::Revoked)
        ->and($usage->revoked_at)->not->toBeNull()
        ->and($usage->meta['revocation_reason'])->toBe('test reason');

    Event::assertDispatched(UsageRevoked::class, function ($event) use ($usage) {
        return $event->usage->id === $usage->id && $event->reason === 'test reason';
    });
});

test('cannot revoke already revoked usage', function () {
    $usage = $this->createUsage($this->license);
    $usage->revoke();

    $originalRevokedAt = $usage->revoked_at;
    $usage->revoke('second attempt');

    expect($usage->revoked_at->toDateTimeString())->toBe($originalRevokedAt->toDateTimeString());
});

test('can check if usage is active', function () {
    $activeUsage = $this->createUsage($this->license);
    $revokedUsage = $this->createUsage($this->license);
    $revokedUsage->revoke();

    expect($activeUsage->isActive())->toBeTrue()
        ->and($revokedUsage->isActive())->toBeFalse();
});

test('can check if usage is stale', function () {
    $license = $this->createLicense([
        'meta' => ['policies' => ['usage_inactivity_auto_revoke_days' => 7]],
    ]);

    $staleUsage = $this->createUsage($license, [
        'last_seen_at' => now()->subDays(8),
    ]);

    $activeUsage = $this->createUsage($license, [
        'last_seen_at' => now()->subDays(5),
    ]);

    expect($staleUsage->isStale())->toBeTrue()
        ->and($activeUsage->isStale())->toBeFalse();
});

test('stale check returns false when policy disabled', function () {
    $license = $this->createLicense([
        'meta' => ['policies' => ['usage_inactivity_auto_revoke_days' => null]],
    ]);

    $oldUsage = $this->createUsage($license, [
        'last_seen_at' => now()->subDays(100),
    ]);

    expect($oldUsage->isStale())->toBeFalse();
});

test('can calculate days since last seen', function () {
    $usage = $this->createUsage($this->license, [
        'last_seen_at' => now()->subDays(10)->startOfDay(),
    ]);

    expect($usage->getDaysSinceLastSeen())->toBe(10);
});

test('scope active filters correctly', function () {
    $active1 = $this->createUsage($this->license);
    $active2 = $this->createUsage($this->license);
    $revoked = $this->createUsage($this->license);
    $revoked->revoke();

    $activeUsages = LicenseUsage::active()->get();

    expect($activeUsages)->toHaveCount(2)
        ->and($activeUsages->pluck('id')->toArray())
        ->toContain($active1->id, $active2->id)
        ->not->toContain($revoked->id);
});

test('scope revoked filters correctly', function () {
    $active = $this->createUsage($this->license);
    $revoked1 = $this->createUsage($this->license);
    $revoked2 = $this->createUsage($this->license);
    $revoked1->revoke();
    $revoked2->revoke();

    $revokedUsages = LicenseUsage::revoked()->get();

    expect($revokedUsages)->toHaveCount(2)
        ->and($revokedUsages->pluck('id')->toArray())
        ->toContain($revoked1->id, $revoked2->id)
        ->not->toContain($active->id);
});

test('scope stale filters by days', function () {
    $recent = $this->createUsage($this->license, [
        'last_seen_at' => now()->subDays(3),
    ]);
    $stale = $this->createUsage($this->license, [
        'last_seen_at' => now()->subDays(8),
    ]);

    $staleUsages = LicenseUsage::stale(7)->get();

    expect($staleUsages)->toHaveCount(1)
        ->and($staleUsages->first()->id)->toBe($stale->id);
});

test('scope for fingerprint finds correct usage', function () {
    $fingerprint1 = 'unique-fingerprint-1';
    $fingerprint2 = 'unique-fingerprint-2';

    $usage1 = $this->createUsage($this->license, [
        'usage_fingerprint' => $fingerprint1,
    ]);
    $usage2 = $this->createUsage($this->license, [
        'usage_fingerprint' => $fingerprint2,
    ]);

    $found = LicenseUsage::forFingerprint($fingerprint1)->first();

    expect($found->id)->toBe($usage1->id);
});
