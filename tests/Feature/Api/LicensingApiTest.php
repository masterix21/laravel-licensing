<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Events\LicenseActivated;
use LucaLongo\Licensing\Licensing;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function ensureApiRoutesRegistered(): void
{
    if (Route::has('licensing.activate')) {
        return;
    }

    $routeFile = realpath(__DIR__.'/../../../routes/api.php');
    if (! $routeFile) {
        throw new RuntimeException('API route file not found');
    }

    require $routeFile;
    Route::getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    ensureApiRoutesRegistered();
});

function seedKeys(): void
{
    if (! LicensingKey::findActiveRoot()) {
        LicensingKey::generateRootKey('test-root');
    }

    if (! LicensingKey::findActiveSigning()) {
        $signing = LicensingKey::generateSigningKey('test-signing');
        $signing->save();
        $ca = app(CertificateAuthorityService::class);
        $certificate = $ca->issueSigningCertificate(
            $signing->getPublicKey(),
            $signing->kid,
            now()->subDay(),
            now()->addDays(30)
        );
        $signing->update(['certificate' => $certificate]);
    }
}

function createLicenseWithKey(string $plainKey, array $attributes = []): License
{
    return License::factory()
        ->state(array_merge([
            'key_hash' => License::hashKey($plainKey),
            'status' => LicenseStatus::Pending,
            'max_usages' => 1,
            'meta' => [
                'offline_token' => [
                    'enabled' => true,
                    'ttl_days' => 7,
                    'force_online_after_days' => 14,
                    'clock_skew_seconds' => 60,
                ],
            ],
        ], $attributes))
        ->create();
}

test('license activation registers usage and returns token payload', function () {
    seedKeys();

    $licenseKey = 'LIC-ACTIVATE-1234';
    $license = createLicenseWithKey($licenseKey, [
        'expires_at' => now()->addDays(30),
    ]);

    Event::fake([LicenseActivated::class]);

    $response = postJson(route('licensing.activate'), [
        'license_key' => $licenseKey,
        'fingerprint' => 'device-fingerprint-1',
        'metadata' => ['client_type' => 'desktop'],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'license' => ['id', 'status', 'max_usages'],
                'usage' => ['id', 'fingerprint', 'last_seen_at'],
                'token',
                'token_expires_at',
                'refresh_after',
                'force_online_after',
                'public_key_bundle',
            ],
        ]);

    $license->refresh();
    expect($license->status)->toBe(LicenseStatus::Active);
    Event::assertDispatched(LicenseActivated::class);

    $usage = LicenseUsage::where('license_id', $license->id)->first();
    expect($usage)->not->toBeNull()
        ->and($usage->usage_fingerprint)->toBe('device-fingerprint-1');
});

test('activation fails when usage limit is reached', function () {
    seedKeys();

    $licenseKey = 'LIC-LIMIT-0001';
    $license = createLicenseWithKey($licenseKey, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(10),
        'max_usages' => 1,
    ]);

    $licensing = app(Licensing::class);
    $licensing->register($license, 'existing-fingerprint');

    $response = postJson(route('licensing.activate'), [
        'license_key' => $licenseKey,
        'fingerprint' => 'new-device',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'USAGE_LIMIT_REACHED');
});

test('refresh issues new token for active usage', function () {
    seedKeys();

    $licenseKey = 'LIC-REFRESH-1234';
    $license = createLicenseWithKey($licenseKey, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(20),
    ]);

    $licensing = app(Licensing::class);
    $usage = $licensing->register($license, 'refresh-device');

    $firstToken = $licensing->issueToken($license, $usage);

    Carbon::setTestNow(now()->addSecond());
    $response = postJson(route('licensing.refresh'), [
        'license_key' => $licenseKey,
        'fingerprint' => 'refresh-device',
    ]);
    Carbon::setTestNow();

    $response->assertOk()
        ->assertJsonPath('success', true);

    $newToken = Arr::get($response->json(), 'data.token');
    expect($newToken)->not->toBeNull()
        ->and($newToken)->not->toBe($firstToken);
});

test('validate confirms active fingerprint and license state', function () {
    seedKeys();

    $licenseKey = 'LIC-VALIDATE-9999';
    $license = createLicenseWithKey($licenseKey, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(5),
    ]);

    $licensing = app(Licensing::class);
    $licensing->register($license, 'validator-device');

    $response = postJson(route('licensing.validate'), [
        'license_key' => $licenseKey,
        'fingerprint' => 'validator-device',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.license.status', LicenseStatus::Active->value)
        ->assertJsonPath('data.usage.fingerprint', 'validator-device');
});

test('validate fails for mismatched fingerprint', function () {
    seedKeys();

    $licenseKey = 'LIC-VALIDATE-FAIL';
    $license = createLicenseWithKey($licenseKey, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(5),
    ]);

    app(Licensing::class)->register($license, 'known-fingerprint');

    $response = postJson(route('licensing.validate'), [
        'license_key' => $licenseKey,
        'fingerprint' => 'unknown-fingerprint',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'FINGERPRINT_MISMATCH');
});

test('heartbeat updates usage metadata and timestamp', function () {
    seedKeys();

    $licenseKey = 'LIC-HEARTBEAT-1';
    $license = createLicenseWithKey($licenseKey, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(15),
    ]);

    $licensing = app(Licensing::class);
    $usage = $licensing->register($license, 'heartbeat-device');

    $originalTimestamp = $usage->last_seen_at;

    Carbon::setTestNow(now()->addMinutes(2));

    $response = postJson(route('licensing.heartbeat'), [
        'license_key' => $licenseKey,
        'fingerprint' => 'heartbeat-device',
        'data' => ['app_version' => '1.2.3'],
    ]);

    Carbon::setTestNow();

    $response->assertOk()->assertJsonPath('success', true);

    $usage->refresh();
    expect($usage->last_seen_at)->toBeGreaterThan($originalTimestamp)
        ->and($usage->meta['app_version'] ?? null)->toBe('1.2.3');
});

test('license detail endpoint returns license information', function () {
    seedKeys();

    $licenseKey = 'LIC-DETAIL-0005';
    $license = createLicenseWithKey($licenseKey, [
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(60),
    ]);

    app(Licensing::class)->register($license, 'detail-device');

    $response = getJson(route('licensing.licenses.show', ['licenseKey' => $licenseKey]));

    $response->assertOk()
        ->assertJsonPath('data.license.id', $license->uid)
        ->assertJsonPath('data.license.active_usages', 1)
        ->assertJsonPath('data.license.available_seats', 0);
});

test('health endpoint reports healthy status when keys exist', function () {
    seedKeys();

    $response = getJson(route('licensing.health'));

    $response->assertOk()
        ->assertJsonPath('data.status', 'healthy');
});
