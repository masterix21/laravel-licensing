<?php

use App\Models\User;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\TrialStatus;
use LucaLongo\Licensing\Exceptions\TrialAlreadyExistsException;
use LucaLongo\Licensing\Exceptions\TrialResetAttemptException;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTrial;
use LucaLongo\Licensing\Services\TrialService;

beforeEach(function () {
    $this->trialService = app(TrialService::class);
    $this->license = License::factory()->create([
        'licensable_type' => User::class,
        'licensable_id' => 1,
        'status' => LicenseStatus::Pending,
    ]);
});

it('can start trial', function () {
    $trial = $this->trialService->startTrial(
        license: $this->license,
        fingerprint: 'test-device-123',
        durationDays: 7
    );

    expect($trial)->toBeInstanceOf(LicenseTrial::class)
        ->and($trial->status)->toBe(TrialStatus::Active)
        ->and($trial->duration_days)->toBe(7)
        ->and($trial->started_at)->not->toBeNull()
        ->and($trial->expires_at)->not->toBeNull();
    
    $this->license->refresh();
    expect($this->license->status)->toBe(LicenseStatus::Active);
});

it('cannot start duplicate trial', function () {
    $fingerprint = 'test-device-123';

    $this->trialService->startTrial($this->license, $fingerprint);

    $this->trialService->startTrial($this->license, $fingerprint);
})->throws(TrialAlreadyExistsException::class);

it('cannot reset trial with same fingerprint', function () {
    $license2 = License::factory()->create([
        'licensable_type' => User::class,
        'licensable_id' => 2,
    ]);
    $fingerprint = 'test-device-123';

    $trial = $this->trialService->startTrial($this->license, $fingerprint);
    $trial->expire();

    $this->trialService->startTrial($license2, $fingerprint);
})->throws(TrialResetAttemptException::class);

it('can convert trial to license', function () {
    $trial = $this->trialService->startTrial($this->license, 'test-device-123');
    
    $convertedLicense = $this->trialService->convertTrial(
        trial: $trial,
        trigger: 'user_purchase',
        value: 99.99
    );

    $trial->refresh();
    
    expect($trial->status)->toBe(TrialStatus::Converted)
        ->and($trial->converted_at)->not->toBeNull()
        ->and($trial->conversion_trigger)->toBe('user_purchase')
        ->and($trial->conversion_value)->toEqual(99.99)
        ->and($convertedLicense->status)->toBe(LicenseStatus::Active);
});

it('can extend trial', function () {
    $trial = $this->trialService->startTrial($this->license, 'test-device-123', 7);
    
    $originalExpiry = $trial->expires_at;
    
    $extendedTrial = $this->trialService->extendTrial(
        trial: $trial,
        days: 3,
        reason: 'Customer requested extension'
    );

    expect($extendedTrial->is_extended)->toBeTrue()
        ->and($extendedTrial->extension_days)->toBe(3)
        ->and($extendedTrial->extension_reason)->toBe('Customer requested extension')
        ->and($extendedTrial->expires_at->gt($originalExpiry))->toBeTrue();
});

it('cannot extend trial twice', function () {
    $trial = $this->trialService->startTrial($this->license, 'test-device-123');
    
    $this->trialService->extendTrial($trial, 3);
    
    $this->trialService->extendTrial($trial, 3);
})->throws(\RuntimeException::class);

it('marks expired trials correctly', function () {
    $trial = $this->trialService->startTrial($this->license, 'test-device-123', 7);
    $trial->update(['expires_at' => now()->subDay()]);
    
    $expiredCount = $this->trialService->checkExpiredTrials();
    
    $trial->refresh();
    
    expect($expiredCount)->toBe(1)
        ->and($trial->status)->toBe(TrialStatus::Expired);
});

it('respects feature restrictions', function () {
    $trial = $this->trialService->startTrial(
        license: $this->license,
        fingerprint: 'test-device-123',
        durationDays: 7,
        limitations: [],
        featureRestrictions: ['export', 'api_access']
    );

    expect($trial->isFeatureRestricted('export'))->toBeTrue()
        ->and($trial->isFeatureRestricted('api_access'))->toBeTrue()
        ->and($trial->isFeatureRestricted('basic_features'))->toBeFalse()
        ->and($this->trialService->canAccessFeature($trial, 'export'))->toBeFalse()
        ->and($this->trialService->canAccessFeature($trial, 'basic_features'))->toBeTrue();
});

it('enforces limitations', function () {
    $trial = $this->trialService->startTrial(
        license: $this->license,
        fingerprint: 'test-device-123',
        durationDays: 7,
        limitations: [
            'max_api_calls' => 100,
            'max_records' => 50,
        ]
    );

    expect($trial->hasLimitation('max_api_calls'))->toBeTrue()
        ->and($trial->getLimitation('max_api_calls'))->toBe(100)
        ->and($this->trialService->checkLimitation($trial, 'max_api_calls', 50))->toBeTrue()
        ->and($this->trialService->checkLimitation($trial, 'max_api_calls', 101))->toBeFalse();
});

it('calculates trial stats correctly', function () {
    // Create multiple licenses for different trial scenarios
    $license = License::factory()->create([
        'licensable_type' => User::class,
        'licensable_id' => 1,
    ]);
    
    // Create various trials
    $trial1 = $this->trialService->startTrial($license, 'device-1');
    
    $trial2 = $this->trialService->startTrial($license, 'device-2');
    $trial2->convert('purchase', 99.99);
    
    $trial3 = $this->trialService->startTrial($license, 'device-3');
    $trial3->expire();
    
    $stats = $this->trialService->getTrialStats($license);
    
    expect($stats['total_trials'])->toBe(3)
        ->and($stats['active_trials'])->toBe(1)
        ->and($stats['converted_trials'])->toBe(1)
        ->and($stats['expired_trials'])->toBe(1)
        ->and($stats['cancelled_trials'])->toBe(0)
        ->and($stats['conversion_rate'])->toBe(33.33)
        ->and($stats['total_conversion_value'])->toEqual(99.99);
});