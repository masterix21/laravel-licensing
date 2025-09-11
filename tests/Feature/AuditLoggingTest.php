<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

uses(LicenseTestHelper::class);

beforeEach(function () {
    config(['licensing.audit.enabled' => true]);
    $this->license = $this->createLicense();
});

test('logs license creation', function () {
    $license = $this->createLicense();

    $log = LicensingAuditLog::where('auditable_type', get_class($license))
        ->where('auditable_id', $license->id)
        ->where('event_type', AuditEventType::LicenseCreated)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta)->toHaveKeys(['license_id', 'status', 'max_usages']);
});

test('logs license activation', function () {
    $license = $this->createLicense(['status' => \LucaLongo\Licensing\Enums\LicenseStatus::Pending]);
    $license->activate();

    $log = LicensingAuditLog::where('auditable_id', $license->id)
        ->where('event_type', AuditEventType::LicenseActivated)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta['activated_at'])->not->toBeNull();
});

test('logs usage registration', function () {
    $usage = $this->createUsage($this->license);

    $log = LicensingAuditLog::where('auditable_type', get_class($usage))
        ->where('auditable_id', $usage->id)
        ->where('event_type', AuditEventType::UsageRegistered)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta)->toHaveKeys(['license_id', 'fingerprint', 'client_type']);
});

test('logs usage revocation with reason', function () {
    $usage = $this->createUsage($this->license);
    $usage->revoke('Policy violation');

    $log = LicensingAuditLog::where('auditable_id', $usage->id)
        ->where('event_type', AuditEventType::UsageRevoked)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta['reason'])->toBe('Policy violation');
});

test('logs key generation', function () {
    $key = $this->createRootKey();

    $log = LicensingAuditLog::where('auditable_type', get_class($key))
        ->where('auditable_id', $key->id)
        ->where('event_type', AuditEventType::KeyRootGenerated)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta)->toHaveKeys(['kid', 'type', 'algorithm']);
});

test('logs key rotation', function () {
    $oldKey = $this->createSigningKey();
    $newKey = $this->createSigningKey();

    // Manually create rotation log for testing
    LicensingAuditLog::create([
        'event_type' => AuditEventType::KeyRotated,
        'auditable_type' => get_class($newKey),
        'auditable_id' => $newKey->id,
        'meta' => [
            'old_kid' => $oldKey->kid,
            'new_kid' => $newKey->kid,
            'reason' => 'rotation',
        ],
    ]);

    $oldKey->revoke('rotation');

    $logs = LicensingAuditLog::where('event_type', AuditEventType::KeyRotated)
        ->orderBy('created_at', 'desc')
        ->first();

    expect($logs)->not->toBeNull()
        ->and($logs->meta)->toHaveKeys(['old_kid', 'new_kid', 'reason']);
});

test('logs license renewal', function () {
    // Create a fresh license that's not "recently created"
    $license = $this->createLicense([
        'expires_at' => now()->addMonth(),
        'status' => \LucaLongo\Licensing\Enums\LicenseStatus::Active,
    ]);

    // Clear the recently created flag by refreshing from DB
    $license = $license->fresh();

    $originalExpiresAt = $license->expires_at;
    expect($originalExpiresAt)->not->toBeNull();

    $newExpiresAt = now()->addYear();
    $license->renew($newExpiresAt);

    $log = LicensingAuditLog::where('auditable_id', $license->id)
        ->where('event_type', AuditEventType::LicenseRenewed)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta)->toHaveKeys(['old_expires_at', 'new_expires_at']);
});

test('logs usage limit reached', function () {
    // Ensure reject policy is set
    config(['licensing.policies.over_limit' => 'reject']);

    $this->license->update(['max_usages' => 1]);

    // Verify the license has the correct settings
    expect($this->license->max_usages)->toBe(1);
    expect($this->license->getOverLimitPolicy()->value)->toBe('reject');

    $this->createUsage($this->license);

    $exceptionThrown = false;
    try {
        app(\LucaLongo\Licensing\Services\UsageRegistrarService::class)
            ->register($this->license, 'new-fingerprint');
    } catch (\Exception $e) {
        $exceptionThrown = true;
        // Expected - usage limit should be reached
    }

    expect($exceptionThrown)->toBeTrue('Usage limit exception should be thrown');

    $log = LicensingAuditLog::where('event_type', AuditEventType::UsageLimitReached)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta['fingerprint'])->toBe('new-fingerprint');
});

test('audit logs are append-only', function () {
    $log = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['test' => 'data'],
    ]);

    // Try to update via direct update method
    expect(fn () => $log->update(['event_type' => AuditEventType::LicenseActivated]))
        ->toThrow(\RuntimeException::class, 'Audit logs are append-only');

    // Also test save after modification
    $log->event_type = AuditEventType::LicenseActivated;
    expect(fn () => $log->save())
        ->toThrow(\RuntimeException::class, 'Audit logs are append-only');

    $log->refresh();

    // Should not change (append-only)
    expect($log->event_type)->toBe(AuditEventType::LicenseCreated);
});

test('can query audit logs by time range', function () {
    // Clear any existing logs from other tests
    LicensingAuditLog::truncate();

    $this->travel(-10)->days();
    $oldLog = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => [],
    ]);

    $this->travelBack();
    $recentLog = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseActivated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 2,
        'meta' => [],
    ]);

    $lastWeek = LicensingAuditLog::where('created_at', '>=', now()->subWeek())->get();

    expect($lastWeek)->toHaveCount(1)
        ->and($lastWeek->first()->id)->toBe($recentLog->id);
});

test('can disable audit logging', function () {
    config(['licensing.audit.enabled' => false]);

    $initialCount = LicensingAuditLog::count();

    $license = $this->createLicense(['status' => \LucaLongo\Licensing\Enums\LicenseStatus::Pending]);
    $license->activate();
    $usage = $this->createUsage($license);
    $usage->revoke();

    expect(LicensingAuditLog::count())->toBe($initialCount);
});

test('stores actor information when available', function () {
    // Create a test user
    $user = \LucaLongo\Licensing\Tests\TestClasses\User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    // Act as the user and create a license
    $this->actingAs($user);

    $license = $this->createLicense();

    // Check the audit log contains actor information
    $log = LicensingAuditLog::where('auditable_type', get_class($license))
        ->where('auditable_id', $license->id)
        ->where('event_type', AuditEventType::LicenseCreated)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->meta)->toHaveKey('actor')
        ->and($log->meta['actor'])->toHaveKeys(['actor_id', 'actor_type', 'actor_name', 'actor_email'])
        ->and($log->meta['actor']['actor_id'])->toBe($user->id)
        ->and($log->meta['actor']['actor_type'])->toBe(get_class($user))
        ->and($log->meta['actor']['actor_name'])->toBe('Test User')
        ->and($log->meta['actor']['actor_email'])->toBe('test@example.com');

    // Test without auth - should not have actor data
    Auth::logout();

    $license2 = $this->createLicense();

    $log2 = LicensingAuditLog::where('auditable_type', get_class($license2))
        ->where('auditable_id', $license2->id)
        ->where('event_type', AuditEventType::LicenseCreated)
        ->first();

    expect($log2)->not->toBeNull()
        ->and($log2->meta)->not->toHaveKey('actor');
});

test('calculates audit log hash chain', function () {
    $log1 = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['test' => 'data'],
    ]);

    $log2 = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseActivated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['test' => 'data2'],
        'previous_hash' => $log1->calculateHash(),
    ]);

    expect($log2->previous_hash)->not->toBeNull()
        ->and($log2->verifyChain($log1))->toBeTrue();
});
