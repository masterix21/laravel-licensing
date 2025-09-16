<?php

use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Services\PasetoTokenService;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

use function Spatie\PestPluginTestTime\testTime;

uses(LicenseTestHelper::class);

beforeEach(function () {
    $this->tokenService = app(PasetoTokenService::class);
    $this->signingKey = $this->createSigningKey();
    $this->license = $this->createLicense([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->addYear(),
        'meta' => [
            'offline_token' => [
                'ttl_days' => 7,
                'force_online_after_days' => 14,
            ],
        ],
    ]);
    $this->usage = $this->createUsage($this->license);
});

test('can issue PASETO token', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);

    expect($token)->not->toBeEmpty()
        ->and($token)->toStartWith('v4.public.');
});

test('token contains required claims', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);
    $claims = $this->tokenService->extractClaims($token);

    expect($claims)
        ->toHaveKeys(['iat', 'nbf', 'exp', 'sub', 'iss', 'license_id', 'license_key_hash', 'usage_fingerprint', 'status', 'max_usages', 'force_online_after'])
        ->and($claims['license_id'])->toBe($this->license->id)
        ->and($claims['usage_fingerprint'])->toBe($this->usage->usage_fingerprint)
        ->and($claims['status'])->toBe('active')
        ->and($claims['max_usages'])->toBe($this->license->max_usages);
});

test('token respects TTL configuration', function () {
    $customTtl = 3;
    $token = $this->tokenService->issue($this->license, $this->usage, [
        'ttl_days' => $customTtl,
    ]);

    $claims = $this->tokenService->extractClaims($token);
    $exp = new DateTime($claims['exp']);
    $iat = new DateTime($claims['iat']);
    $diffDays = $exp->diff($iat)->days;

    expect($diffDays)->toBe($customTtl);
});

test('token includes license expiration when set', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);
    $claims = $this->tokenService->extractClaims($token);

    expect($claims)->toHaveKey('license_expires_at')
        ->and($claims['license_expires_at'])->toBe($this->license->expires_at->format('c'));
});

test('token includes grace period information', function () {
    $graceLicense = $this->createLicense([
        'status' => LicenseStatus::Grace,
        'expires_at' => now()->subDay(),
    ]);
    $graceUsage = $this->createUsage($graceLicense);

    $token = $this->tokenService->issue($graceLicense, $graceUsage);
    $claims = $this->tokenService->extractClaims($token);

    expect($claims)->toHaveKey('grace_until');
});

test('can verify valid token', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);

    $verified = $this->tokenService->verify($token);

    expect($verified)->toBeArray()
        ->and($verified['license_id'])->toBe($this->license->id);
});

test('verification fails for expired token', function () {
    $expiredLicense = $this->createLicense([
        'meta' => ['offline_token' => ['ttl_days' => -1]], // Negative TTL for expired token
    ]);
    $usage = $this->createUsage($expiredLicense);

    // Mock time to create expired token
    $this->travel(-2)->days();
    $token = $this->tokenService->issue($expiredLicense, $usage);
    $this->travelBack();

    $this->tokenService->verify($token);
})->throws(\RuntimeException::class);

test('verification fails when force online required', function () {
    $license = $this->createLicense([
        'meta' => ['offline_token' => [
            'force_online_after_days' => 0,
            'clock_skew_seconds' => 0,
        ]],
    ]);
    $usage = $this->createUsage($license);

    $token = $this->tokenService->issue($license, $usage);

    $this->tokenService->verify($token);
})->throws(\RuntimeException::class, 'Token requires online verification');

test('respects per-license clock skew when verifying', function () {
    testTime()->freeze();

    $license = $this->createLicense([
        'meta' => ['offline_token' => [
            'ttl_days' => 0,
            'clock_skew_seconds' => 120,
        ]],
    ]);

    $usage = $this->createUsage($license);
    $token = $this->tokenService->issue($license, $usage);

    testTime()->addSeconds(60);

    $verified = $this->tokenService->verify($token);

    expect($verified['license_id'])->toBe($license->id);
});

test('can refresh token', function () {
    testTime()->freeze();
    $originalToken = $this->tokenService->issue($this->license, $this->usage);

    testTime()->addSeconds(5); // Ensure different timestamp
    $refreshedToken = $this->tokenService->refresh($originalToken);

    expect($refreshedToken)->not->toBe($originalToken);

    $originalClaims = $this->tokenService->extractClaims($originalToken);
    $refreshedClaims = $this->tokenService->extractClaims($refreshedToken);

    expect($refreshedClaims['license_id'])->toBe($originalClaims['license_id'])
        ->and($refreshedClaims['usage_fingerprint'])->toBe($originalClaims['usage_fingerprint'])
        ->and($refreshedClaims['iat'])->toBeGreaterThan($originalClaims['iat']);
});

test('token footer contains certificate chain', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);

    $parts = explode('.', $token);
    expect($parts)->toHaveCount(4); // v2.public.payload.footer

    $footer = json_decode(base64_decode(strtr($parts[3], '-_', '+/')), true);

    expect($footer)->toHaveKeys(['kid', 'chain'])
        ->and($footer['chain'])->toHaveKeys(['signing', 'root']);
});

test('can verify token offline with public key bundle', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);

    $rootKey = \LucaLongo\Licensing\Models\LicensingKey::findActiveRoot();
    $publicKeyBundle = json_encode([
        'root' => [
            'public_key' => $rootKey->getPublicKey(),
        ],
    ]);

    $verified = $this->tokenService->verifyOffline($token, $publicKeyBundle);

    expect($verified)->toBeArray()
        ->and($verified['license_id'])->toBe($this->license->id);
});

test('offline verification fails with wrong public key', function () {
    $token = $this->tokenService->issue($this->license, $this->usage);

    $wrongBundle = json_encode([
        'root' => [
            'public_key' => 'wrong-public-key',
        ],
    ]);

    $this->tokenService->verifyOffline($token, $wrongBundle);
})->throws(\RuntimeException::class);

test('custom issuer is included in token', function () {
    $customIssuer = 'my-app-licensing';
    $token = $this->tokenService->issue($this->license, $this->usage, [
        'issuer' => $customIssuer,
    ]);

    $claims = $this->tokenService->extractClaims($token);

    expect($claims['iss'])->toBe($customIssuer);
});
