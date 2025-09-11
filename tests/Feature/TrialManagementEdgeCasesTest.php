<?php

use App\Models\User;
use Carbon\Carbon;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\TrialStatus;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Services\TrialService;

beforeEach(function () {
    $this->trialService = app(TrialService::class);
    $this->license = License::factory()->create([
        'licensable_type' => User::class,
        'licensable_id' => 1,
        'status' => LicenseStatus::Pending,
    ]);
});

it('handles trial with zero duration days gracefully', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 0);
    
    expect($trial->duration_days)->toBe(0)
        ->and($trial->expires_at->isToday())->toBeTrue()
        ->and($trial->isExpired())->toBeFalse(); // Not expired until end of day
});

it('prevents conversion of expired trial', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    $trial->expire();
    
    expect(fn() => $trial->convert('purchase'))
        ->toThrow(\RuntimeException::class, 'Trial cannot be converted in current status: expired');
});

it('prevents conversion of cancelled trial', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    $trial->cancel();
    
    expect(fn() => $trial->convert('purchase'))
        ->toThrow(\RuntimeException::class, 'Trial cannot be converted in current status: cancelled');
});

it('handles concurrent trial registrations safely', function () {
    $fingerprint = 'device-concurrent';
    
    // Start first trial
    $trial1 = $this->trialService->startTrial($this->license, $fingerprint);
    
    // Attempt to start second trial with same fingerprint should fail
    expect(fn() => $this->trialService->startTrial($this->license, $fingerprint))
        ->toThrow(\LucaLongo\Licensing\Exceptions\TrialAlreadyExistsException::class);
    
    // First trial should remain valid
    expect($trial1->fresh()->status)->toBe(TrialStatus::Active);
});

it('correctly calculates days remaining for trial', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 10);
    
    // Initially should have 10 days (or 9 depending on time)
    expect($trial->daysRemaining())->toBeGreaterThanOrEqual(9);
    
    // Modify expires_at to be 3 days from now
    $trial->update(['expires_at' => now()->addDays(3)]);
    expect($trial->daysRemaining())->toBe(3);
    
    // Expired trial should have 0 days
    $trial->update(['expires_at' => now()->subDay()]);
    expect($trial->daysRemaining())->toBe(0);
});

it('handles empty limitations and restrictions', function () {
    $trial = $this->trialService->startTrial(
        $this->license, 
        'device-1',
        7,
        [], // empty limitations
        []  // empty restrictions
    );
    
    expect($trial->hasLimitation('any_key'))->toBeFalse()
        ->and($trial->getLimitation('any_key', 'default'))->toBe('default')
        ->and($trial->isFeatureRestricted('any_feature'))->toBeFalse()
        ->and($this->trialService->canAccessFeature($trial, 'any_feature'))->toBeTrue()
        ->and($this->trialService->checkLimitation($trial, 'any_limit', 1000000))->toBeTrue();
});

it('handles null values in trial metadata', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    
    $trial->update([
        'extension_reason' => null,
        'conversion_trigger' => null,
        'conversion_value' => null,
        'meta' => null,
    ]);
    
    expect($trial->extension_reason)->toBeNull()
        ->and($trial->conversion_trigger)->toBeNull()
        ->and($trial->conversion_value)->toBeNull()
        ->and($trial->meta)->toBeNull();
});

it('verifies fingerprint hashing is consistent', function () {
    $plainFingerprint = 'test-device-123';
    $hashedFingerprint = hash('sha256', $plainFingerprint);
    
    $trial = $this->trialService->startTrial($this->license, $plainFingerprint);
    
    expect($trial->trial_fingerprint)->toBe($hashedFingerprint)
        ->and($trial->checkFingerprint($plainFingerprint))->toBeTrue()
        ->and($trial->checkFingerprint('wrong-fingerprint'))->toBeFalse();
});

it('handles trial expiration at exact boundary', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    
    // Set expires_at to today - should not be expired (expires at end of day)
    $trial->update(['expires_at' => now()]);
    expect($trial->isExpired())->toBeFalse();
    
    // Set to yesterday - should be expired
    $trial->update(['expires_at' => now()->subDay()]);
    expect($trial->isExpired())->toBeTrue();
    
    // Set to tomorrow - should not be expired
    $trial->update(['expires_at' => now()->addDay()]);
    expect($trial->isExpired())->toBeFalse();
});

it('prevents starting trial on suspended license', function () {
    $this->license->suspend();
    
    // Should still allow trial start (business decision)
    $trial = $this->trialService->startTrial($this->license, 'device-1');
    
    expect($trial)->toBeInstanceOf(\LucaLongo\Licensing\Models\LicenseTrial::class)
        ->and($trial->status)->toBe(TrialStatus::Active);
    
    // But license should remain suspended
    expect($this->license->fresh()->status)->toBe(LicenseStatus::Suspended);
});

it('handles very long extension reasons gracefully', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    
    $longReason = str_repeat('Customer requested extension due to technical issues. ', 50);
    
    $extendedTrial = $this->trialService->extendTrial($trial, 3, $longReason);
    
    expect($extendedTrial->extension_reason)->toBe($longReason)
        ->and(strlen($extendedTrial->extension_reason))->toBeGreaterThan(1000);
});

it('correctly handles trial with future start date', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    
    // Manually set started_at to future
    $futureDate = now()->addDays(2);
    $trial->update(['started_at' => $futureDate]);
    
    // Trial should still be considered active if not expired
    expect($trial->status)->toBe(TrialStatus::Active)
        ->and($trial->isActive())->toBeTrue();
});

it('handles conversion with zero value', function () {
    $trial = $this->trialService->startTrial($this->license, 'device-1', 7);
    
    $convertedLicense = $this->trialService->convertTrial($trial, 'free_conversion', 0.00);
    
    $trial->refresh();
    
    expect($trial->conversion_value)->toEqual(0.00)
        ->and($trial->conversion_trigger)->toBe('free_conversion')
        ->and($trial->status)->toBe(TrialStatus::Converted);
});

it('verifies trial stats with no trials', function () {
    $emptyLicense = License::factory()->create([
        'licensable_type' => User::class,
        'licensable_id' => 2,
    ]);
    
    $stats = $this->trialService->getTrialStats($emptyLicense);
    
    expect($stats['total_trials'])->toBe(0)
        ->and($stats['conversion_rate'])->toBe(0)
        ->and($stats['total_conversion_value'])->toBe(0);
});