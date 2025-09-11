<?php

use Illuminate\Support\Facades\Event;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Events\LicenseActivated;
use LucaLongo\Licensing\Events\LicenseExpired;
use LucaLongo\Licensing\Events\LicenseRenewed;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

uses(LicenseTestHelper::class);

beforeEach(function () {
    Event::fake();
});

test('can create a license with hashed key', function () {
    $license = License::create([
        'key_hash' => License::hashKey('TEST-KEY-123'),
        'status' => LicenseStatus::Pending,
        'licensable_type' => 'App\Models\User',
        'licensable_id' => 1,
        'max_usages' => 5,
    ]);

    expect($license)->toBeInstanceOf(License::class)
        ->and($license->status)->toBe(LicenseStatus::Pending)
        ->and($license->max_usages)->toBe(5);
});

test('can find license by key', function () {
    $key = 'UNIQUE-LICENSE-KEY';
    $license = $this->createLicense([
        'key_hash' => License::hashKey($key),
    ]);

    $found = License::findByKey($key);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($license->id);
});

test('automatically generates uid on creation', function () {
    $license = $this->createLicense();
    
    expect($license->uid)->not->toBeNull()
        ->and(strlen($license->uid))->toBe(26)
        ->and($license->uid)->toMatch('/^[0-9a-z]{26}$/');
});

test('can find license by uid', function () {
    $license = $this->createLicense();
    
    $found = License::findByUid($license->uid);
    
    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($license->id);
});

test('uid is unique across licenses', function () {
    $license1 = $this->createLicense();
    $license2 = $this->createLicense();
    
    expect($license1->uid)->not->toBe($license2->uid);
});

test('can verify license key', function () {
    $key = 'SECRET-LICENSE-KEY';
    $license = $this->createLicense([
        'key_hash' => License::hashKey($key),
    ]);

    expect($license->verifyKey($key))->toBeTrue()
        ->and($license->verifyKey('WRONG-KEY'))->toBeFalse();
});

test('can activate a pending license', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Pending,
        'activated_at' => null,
    ]);

    $license->activate();

    expect($license->status)->toBe(LicenseStatus::Active)
        ->and($license->activated_at)->not->toBeNull();

    Event::assertDispatched(LicenseActivated::class, function ($event) use ($license) {
        return $event->license->id === $license->id;
    });
});

test('cannot activate already active license', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
    ]);

    $license->activate();
})->throws(\RuntimeException::class, 'License cannot be activated in current status: active');

test('can renew license', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->addDays(7),
    ]);

    $newExpiration = now()->addYear();
    $license->renew($newExpiration);

    expect($license->expires_at->format('Y-m-d'))->toBe($newExpiration->format('Y-m-d'))
        ->and($license->renewals)->toHaveCount(1);

    Event::assertDispatched(LicenseRenewed::class);
});

test('can suspend and cancel license', function () {
    $license = $this->createLicense(['status' => LicenseStatus::Active]);

    $license->suspend();
    expect($license->status)->toBe(LicenseStatus::Suspended);

    $license->cancel();
    expect($license->status)->toBe(LicenseStatus::Cancelled);
});

test('can check if license is usable', function () {
    $activeLicense = $this->createLicense(['status' => LicenseStatus::Active]);
    $graceLicense = $this->createLicense(['status' => LicenseStatus::Grace]);
    $expiredLicense = $this->createLicense(['status' => LicenseStatus::Expired]);

    expect($activeLicense->isUsable())->toBeTrue()
        ->and($graceLicense->isUsable())->toBeTrue()
        ->and($expiredLicense->isUsable())->toBeFalse();
});

test('can check expiration status', function () {
    $expired = $this->createLicense(['expires_at' => now()->subDay()]);
    $valid = $this->createLicense(['expires_at' => now()->addDay()]);
    $perpetual = $this->createLicense(['expires_at' => null]);

    expect($expired->isExpired())->toBeTrue()
        ->and($valid->isExpired())->toBeFalse()
        ->and($perpetual->isExpired())->toBeFalse();
});

test('can calculate days until expiration', function () {
    $license = $this->createLicense(['expires_at' => now()->addDays(30)]);

    expect($license->daysUntilExpiration())->toBe(30);

    $perpetual = $this->createLicense(['expires_at' => null]);
    expect($perpetual->daysUntilExpiration())->toBeNull();
});

test('can check available seats', function () {
    $license = $this->createLicense(['max_usages' => 3]);

    expect($license->hasAvailableSeats())->toBeTrue()
        ->and($license->getAvailableSeats())->toBe(3);

    $this->createUsage($license);
    $this->createUsage($license);

    expect($license->getAvailableSeats())->toBe(1);

    $this->createUsage($license);

    expect($license->hasAvailableSeats())->toBeFalse()
        ->and($license->getAvailableSeats())->toBe(0);
});

test('can transition to grace period', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    $license->transitionToGrace();

    expect($license->status)->toBe(LicenseStatus::Grace);
});

test('can transition to expired after grace period', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Grace,
        'expires_at' => now()->subDays(15), // Default grace is 14 days
    ]);

    $license->transitionToExpired();

    expect($license->status)->toBe(LicenseStatus::Expired);
    Event::assertDispatched(LicenseExpired::class);
});

test('grace period respects configuration', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Grace,
        'expires_at' => now()->subDays(10),
        'meta' => ['policies' => ['grace_days' => 7]],
    ]);

    expect($license->gracePeriodExpired())->toBeTrue();

    $license2 = $this->createLicense([
        'status' => LicenseStatus::Grace,
        'expires_at' => now()->subDays(5),
        'meta' => ['policies' => ['grace_days' => 7]],
    ]);

    expect($license2->gracePeriodExpired())->toBeFalse();
});
