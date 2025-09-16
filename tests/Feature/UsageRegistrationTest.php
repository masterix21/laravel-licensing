<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LucaLongo\Licensing\Events\UsageLimitReached;
use LucaLongo\Licensing\Events\UsageRegistered;
use LucaLongo\Licensing\Services\UsageRegistrarService;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

use function Spatie\PestPluginTestTime\testTime;

uses(LicenseTestHelper::class);

beforeEach(function () {
    Event::fake();
    $this->registrar = app(UsageRegistrarService::class);
    $this->license = $this->createLicense(['max_usages' => 3]);
});

test('can register new usage', function () {
    $fingerprint = $this->generateFingerprint();

    $usage = $this->registrar->register($this->license, $fingerprint, [
        'client_type' => 'desktop',
        'name' => 'Test Machine',
    ]);

    expect($usage)->not->toBeNull()
        ->and($usage->usage_fingerprint)->toBe($fingerprint)
        ->and($usage->client_type)->toBe('desktop')
        ->and($usage->name)->toBe('Test Machine')
        ->and($usage->isActive())->toBeTrue();

    Event::assertDispatched(UsageRegistered::class);
});

test('returns existing usage for same fingerprint', function () {
    $fingerprint = $this->generateFingerprint();

    $usage1 = $this->registrar->register($this->license, $fingerprint);
    $usage2 = $this->registrar->register($this->license, $fingerprint);

    expect($usage2->id)->toBe($usage1->id)
        ->and($this->license->usages()->count())->toBe(1);
});

test('updates heartbeat for existing usage', function () {
    testTime()->freeze();
    $fingerprint = $this->generateFingerprint();
    $usage = $this->registrar->register($this->license, $fingerprint);

    $originalTime = $usage->last_seen_at;
    testTime()->addSeconds(5);

    $this->registrar->register($this->license, $fingerprint);
    $usage->refresh();

    expect($usage->last_seen_at)->toBeGreaterThan($originalTime);
});

test('enforces max usage limit with reject policy', function () {
    $this->license->update([
        'max_usages' => 2,
        'meta' => ['policies' => ['over_limit' => 'reject']],
    ]);

    $this->registrar->register($this->license, 'fingerprint1');
    $this->registrar->register($this->license, 'fingerprint2');

    $this->registrar->register($this->license, 'fingerprint3');
})->throws(\RuntimeException::class, 'License usage limit reached');

test('auto replaces oldest usage when limit reached', function () {
    testTime()->freeze();
    $this->license->update([
        'max_usages' => 2,
        'meta' => ['policies' => ['over_limit' => 'auto_replace_oldest']],
    ]);

    $usage1 = $this->registrar->register($this->license, 'fingerprint1');
    testTime()->addSeconds(5);
    $usage2 = $this->registrar->register($this->license, 'fingerprint2');

    testTime()->addSeconds(5);
    $usage3 = $this->registrar->register($this->license, 'fingerprint3');

    $usage1->refresh();

    expect($usage1->isActive())->toBeFalse()
        ->and($usage2->isActive())->toBeTrue()
        ->and($usage3->isActive())->toBeTrue()
        ->and($this->license->activeUsages()->count())->toBe(2);
});

test('emits usage limit reached event', function () {
    $this->license->update(['max_usages' => 1]);

    $this->registrar->register($this->license, 'fingerprint1');

    try {
        $this->registrar->register($this->license, 'fingerprint2');
    } catch (\Exception $e) {
        // Expected
    }

    Event::assertDispatched(UsageLimitReached::class, function ($event) {
        return $event->license->id === $this->license->id
            && $event->fingerprint === 'fingerprint2';
    });
});

test('can check if registration is allowed', function () {
    $this->license->update(['max_usages' => 2]);

    expect($this->registrar->canRegister($this->license, 'new-fingerprint'))->toBeTrue();

    $this->registrar->register($this->license, 'fingerprint1');
    $this->registrar->register($this->license, 'fingerprint2');

    expect($this->registrar->canRegister($this->license, 'fingerprint3'))->toBeFalse()
        ->and($this->registrar->canRegister($this->license, 'fingerprint1'))->toBeTrue(); // Existing
});

test('prevents double registration once seat limit is hit', function () {
    $this->license->update(['max_usages' => 1]);

    $first = DB::transaction(fn () => $this->registrar->register($this->license, 'fingerprint1'));

    expect($first->isActive())->toBeTrue();

    expect(fn () => DB::transaction(fn () => $this->registrar->register($this->license, 'fingerprint2')))
        ->toThrow(\RuntimeException::class, 'License usage limit reached');

    expect($this->license->activeUsages()->count())->toBe(1);
});

test('respects global fingerprint uniqueness', function () {
    $fingerprint = 'unique-global-fingerprint';

    $license1 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'global']],
    ]);
    $license2 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'global']],
    ]);

    $usage1 = $this->registrar->register($license1, $fingerprint);

    expect($this->registrar->findByFingerprint($license2, $fingerprint))
        ->not->toBeNull()
        ->and($this->registrar->canRegister($license2, $fingerprint))
        ->toBeFalse();
});

test('respects per-license fingerprint scope', function () {
    $fingerprint = 'shared-fingerprint';

    $license1 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'license']],
    ]);
    $license2 = $this->createLicense([
        'meta' => ['policies' => ['unique_usage_scope' => 'license']],
    ]);

    $usage1 = $this->registrar->register($license1, $fingerprint);

    expect($this->registrar->findByFingerprint($license2, $fingerprint))
        ->toBeNull()
        ->and($this->registrar->canRegister($license2, $fingerprint))
        ->toBeTrue();

    $usage2 = $this->registrar->register($license2, $fingerprint);

    expect($usage2->id)->not->toBe($usage1->id);
});

test('can revoke usage', function () {
    $usage = $this->registrar->register($this->license, 'fingerprint');

    $this->registrar->revoke($usage, 'manual revocation');

    expect($usage->isActive())->toBeFalse()
        ->and($usage->meta['revocation_reason'])->toBe('manual revocation');
});

test('can update heartbeat', function () {
    testTime()->freeze();
    $usage = $this->registrar->register($this->license, 'fingerprint');
    $original = $usage->last_seen_at;

    testTime()->addSeconds(5);
    $this->registrar->heartbeat($usage);
    $usage->refresh();

    expect($usage->last_seen_at)->toBeGreaterThan($original);
});

test('cannot heartbeat revoked usage', function () {
    $usage = $this->registrar->register($this->license, 'fingerprint');
    $this->registrar->revoke($usage);

    $this->registrar->heartbeat($usage);
})->throws(\RuntimeException::class, 'Cannot heartbeat revoked usage');

test('registers usage without request binding when metadata provided', function () {
    app()->forgetInstance('request');
    app()->offsetUnset('request');

    $fingerprint = $this->generateFingerprint('cli');

    $usage = $this->registrar->register($this->license, $fingerprint, [
        'ip' => '127.0.0.1',
        'user_agent' => 'artisan-cli',
    ]);

    expect($usage->ip)->toBe('127.0.0.1')
        ->and($usage->user_agent)->toBe('artisan-cli');
});
