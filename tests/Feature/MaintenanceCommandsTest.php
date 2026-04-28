<?php

use Illuminate\Support\Facades\Event;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Events\LicenseExpiringSoon;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

uses(LicenseTestHelper::class)->group('cli');

test('licensing:check reports failure without keys', function () {
    $this->artisan('licensing:check')
        ->expectsOutputToContain('Root key')
        ->expectsOutputToContain('FAIL')
        ->assertExitCode(1);
});

test('licensing:check passes after root and signing keys exist', function () {
    $this->createSigningKey();

    $this->artisan('licensing:check')
        ->expectsOutputToContain('Installation OK.')
        ->assertExitCode(0);
});

test('licensing:check-expirations transitions active expired licenses to grace', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations')->assertExitCode(0);

    expect($license->fresh()->status)->toBe(LicenseStatus::Grace);
});

test('licensing:check-expirations transitions grace licenses past grace window to expired', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Grace,
        'expires_at' => now()->subDays(config('licensing.policies.grace_days') + 1),
    ]);

    $this->artisan('licensing:check-expirations')->assertExitCode(0);

    expect($license->fresh()->status)->toBe(LicenseStatus::Expired);
});

test('licensing:check-expirations dry-run leaves licenses untouched', function () {
    $license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('licensing:check-expirations', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertExitCode(0);

    expect($license->fresh()->status)->toBe(LicenseStatus::Active);
});

test('licensing:check-expirations notifies licenses expiring soon', function () {
    Event::fake([LicenseExpiringSoon::class]);

    $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->addDays(3),
    ]);

    $this->artisan('licensing:check-expirations', ['--notify' => true])->assertExitCode(0);

    Event::assertDispatched(LicenseExpiringSoon::class);
});

test('licensing:cleanup-usages skips when policy disabled', function () {
    config()->set('licensing.policies.usage_inactivity_auto_revoke_days', null);

    $this->artisan('licensing:cleanup-usages')
        ->expectsOutputToContain('Auto-revoke disabled')
        ->assertExitCode(0);
});

test('licensing:cleanup-usages revokes inactive usages', function () {
    config()->set('licensing.policies.usage_inactivity_auto_revoke_days', 30);

    $license = $this->createLicense();
    $stale = $this->createUsage($license, ['last_seen_at' => now()->subDays(60)]);
    $fresh = $this->createUsage($license, ['last_seen_at' => now()->subDay()]);

    $this->artisan('licensing:cleanup-usages')->assertExitCode(0);

    expect($stale->fresh()->status)->toBe(UsageStatus::Revoked)
        ->and($fresh->fresh()->status)->toBe(UsageStatus::Active);
});
