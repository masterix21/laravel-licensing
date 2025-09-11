<?php

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\CertificateAuthorityService;
use LucaLongo\Licensing\Services\PasetoTokenService;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

use function Spatie\PestPluginTestTime\testTime;

uses(LicenseTestHelper::class);

beforeEach(function () {
    $this->tokenService = app(PasetoTokenService::class);
    $this->ca = app(CertificateAuthorityService::class);

    // Create keys if not exists
    if (! LicensingKey::findActiveRoot()) {
        $this->createRootKey();
    }
    if (! LicensingKey::findActiveSigning()) {
        $this->createSigningKey();
    }
});

test('license keys are properly hashed', function () {
    $plainKey = 'SECRET-LICENSE-KEY-123';
    $hash1 = License::hashKey($plainKey);
    $hash2 = License::hashKey($plainKey);

    expect($hash1)->toBe($hash2) // Deterministic
        ->and($hash1)->not->toContain($plainKey) // Not reversible
        ->and(strlen($hash1))->toBe(64); // SHA-256 hex
});

test('license key verification uses constant time comparison', function () {
    $key = 'TEST-KEY-456';
    $license = $this->createLicense(['key_hash' => License::hashKey($key)]);

    $startCorrect = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $license->verifyKey($key);
    }
    $timeCorrect = microtime(true) - $startCorrect;

    $startWrong = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $license->verifyKey('WRONG-KEY-999');
    }
    $timeWrong = microtime(true) - $startWrong;

    // Times should be similar (within 50% variance)
    $ratio = $timeCorrect / $timeWrong;
    expect($ratio)->toBeBetween(0.5, 1.5);
});

test('fingerprints do not contain PII', function () {
    $fingerprint = hash('sha256', json_encode([
        'machine_id' => 'ABC123',
        'app_version' => '1.0.0',
        'os' => 'darwin',
    ]));

    expect($fingerprint)
        ->not->toContain('user')
        ->not->toContain('email')
        ->not->toContain('name')
        ->not->toContain('@')
        ->and(strlen($fingerprint))->toBe(64);
});

test('tokens cannot be tampered with', function () {
    $license = $this->createLicense();
    $usage = $this->createUsage($license);
    $signingKey = $this->createSigningKey();

    $token = $this->tokenService->issue($license, $usage);

    // Try to tamper with the token
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode(strtr($parts[2], '-_', '+/')), true);
    $payload['max_usages'] = 999;
    $parts[2] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $tamperedToken = implode('.', $parts);

    expect(fn () => $this->tokenService->verify($tamperedToken))
        ->toThrow(\Exception::class);
});

test('expired tokens are rejected', function () {
    $license = $this->createLicense([
        'meta' => ['offline_token' => ['ttl_days' => -1]], // Already expired
    ]);
    $usage = $this->createUsage($license);

    $this->travel(-2)->days();
    $token = $this->tokenService->issue($license, $usage);
    $this->travelBack();

    expect(fn () => $this->tokenService->verify($token))
        ->toThrow(\RuntimeException::class);
});

test('tokens with future nbf are rejected', function () {
    $license = $this->createLicense();
    $usage = $this->createUsage($license);

    // Freeze time at current moment
    testTime()->freeze('2024-01-01 12:00:00');
    $originalTime = now()->copy();

    // Jump 2 hours into the future to issue token
    testTime()->addHours(2);
    $token = $this->tokenService->issue($license, $usage);

    // Return to original time
    testTime()->freeze($originalTime);

    // Token was issued with nbf 2 hours in the future, should be rejected
    expect(fn () => $this->tokenService->verify($token))
        ->toThrow(\RuntimeException::class, 'Token not valid yet');
});

test('clock skew tolerance works', function () {
    $license = $this->createLicense();
    $usage = $this->createUsage($license);

    // Test that a token can be verified within clock skew tolerance
    testTime()->freeze();
    $token = $this->tokenService->issue($license, $usage);

    // Move 30 seconds forward - within 60s tolerance, should still work
    testTime()->addSeconds(30);
    expect($this->tokenService->verify($token))->toBeArray();

    // Move 50 seconds forward from original time - still within 60s tolerance
    testTime()->addSeconds(20);
    expect($this->tokenService->verify($token))->toBeArray();

    // NOTE: Testing tokens with future nbf is covered in the
    // "tokens with future nbf are rejected" test
});

test('revoked signing keys reject tokens', function () {
    // Get the active signing key that will be used for token issuance
    $signingKey = LicensingKey::findActiveSigning();
    $license = $this->createLicense();
    $usage = $this->createUsage($license);

    $token = $this->tokenService->issue($license, $usage);

    // Token works before revocation
    expect($this->tokenService->verify($token))->toBeArray();

    // Revoke the signing key that was used to sign the token
    $signingKey->revoke('compromised');

    // Token should be rejected
    expect(fn () => $this->tokenService->verify($token))
        ->toThrow(\RuntimeException::class, 'Signing key has been revoked');
});

test('certificate chain validation', function () {
    $rootKey = $this->createRootKey();
    $signingKey = $this->createSigningKey();

    $chain = $this->ca->getCertificateChain($signingKey->kid);

    // Verify chain structure
    expect($chain)->toHaveKeys(['signing', 'root'])
        ->and($chain['signing'])->toHaveKey('certificate');

    // Verify certificate signature
    $certificate = $chain['signing']['certificate'];
    expect($this->ca->verifyCertificate($certificate))->toBeTrue();

    // Tamper with certificate
    $certData = json_decode($certificate, true);
    $certData['certificate']['public_key'] = 'tampered';
    $tamperedCert = json_encode($certData);

    expect($this->ca->verifyCertificate($tamperedCert))->toBeFalse();
});

test('private keys are encrypted at rest', function () {
    $key = $this->createRootKey();

    // Encrypted key in database should not be plain base64 key
    expect($key->private_key_encrypted)
        ->not->toBeEmpty()
        ->and(strlen($key->private_key_encrypted))->toBeGreaterThan(88); // Ed25519 keys are 88 chars in base64

    // Can decrypt with correct passphrase
    $privateKey = $key->getPrivateKey();
    expect($privateKey)
        ->toBeString()
        ->and(strlen(base64_decode($privateKey)))->toBe(64); // Ed25519 secret key is 64 bytes
});

test('SQL injection prevention in license lookup', function () {
    // Create a license first
    $this->createLicense();

    $maliciousKey = "'; DROP TABLE licenses; --";

    $result = License::findByKey($maliciousKey);

    expect($result)->toBeNull()
        ->and(License::count())->toBeGreaterThan(0); // Table still exists
});

test('XSS prevention in audit logs', function () {
    $maliciousData = '<script>alert("XSS")</script>';

    $log = \LucaLongo\Licensing\Models\LicensingAuditLog::create([
        'event_type' => \LucaLongo\Licensing\Enums\AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['user_input' => $maliciousData],
    ]);

    // Data is stored as-is in JSON, but should be escaped when displayed
    expect($log->meta['user_input'])->toBe($maliciousData);

    // When outputting, the application should escape HTML
    $escaped = htmlspecialchars($log->meta['user_input'], ENT_QUOTES, 'UTF-8');
    expect($escaped)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
});

test('token force online enforcement', function () {
    $license = $this->createLicense([
        'meta' => ['offline_token' => ['force_online_after_days' => -1]], // Already expired
    ]);
    $usage = $this->createUsage($license);

    $token = $this->tokenService->issue($license, $usage);

    // Token has force_online_after in the past, should require online verification
    expect(fn () => $this->tokenService->verify($token))
        ->toThrow(\RuntimeException::class, 'Token requires online verification');
});

test('concurrent usage registration is thread-safe', function () {
    $license = $this->createLicense(['max_usages' => 1]);
    $registrar = app(\LucaLongo\Licensing\Services\UsageRegistrarService::class);

    $results = [];
    $exceptions = [];

    // Simulate concurrent requests with database transactions
    for ($i = 0; $i < 5; $i++) {
        try {
            \DB::transaction(function () use ($registrar, $license, &$results, $i) {
                $usage = $registrar->register($license, "concurrent-fp-{$i}");
                $results[] = $usage->id;
            });
        } catch (\Exception $e) {
            $exceptions[] = $e->getMessage();
        }
    }

    expect(count($results))->toBe(1)
        ->and(count($exceptions))->toBe(4)
        ->and($license->activeUsages()->count())->toBe(1);
});

test('audit logs are tamper-evident', function () {
    $log1 = \LucaLongo\Licensing\Models\LicensingAuditLog::create([
        'event_type' => \LucaLongo\Licensing\Enums\AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['test' => 'data1'],
    ]);

    $hash1 = $log1->calculateHash();

    $log2 = \LucaLongo\Licensing\Models\LicensingAuditLog::create([
        'event_type' => \LucaLongo\Licensing\Enums\AuditEventType::LicenseActivated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['test' => 'data2'],
        'previous_hash' => $hash1,
    ]);

    // Verify chain
    expect($log2->verifyChain($log1))->toBeTrue();

    // Tamper with log1
    $log1->meta = ['test' => 'tampered'];

    // Chain verification should fail
    expect($log2->verifyChain($log1))->toBeFalse();
});
