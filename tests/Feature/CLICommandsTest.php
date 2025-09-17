<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;

uses(LicenseTestHelper::class);

beforeEach(function () {
    // Ensure clean key storage
    $keyPath = config('licensing.crypto.keystore.path');
    if (File::exists($keyPath)) {
        File::deleteDirectory($keyPath);
    }

    LicensingKey::forgetCachedPassphrase();
});

test('can make root key via CLI', function () {
    $this->artisan('licensing:keys:make-root')
        ->expectsOutput('Generating root key pair...')
        ->expectsOutputToContain('Root key generated successfully')
        ->expectsOutputToContain('Key ID:')
        ->expectsOutputToContain('Public key bundle exported to:')
        ->assertSuccessful();

    $rootKey = LicensingKey::where('type', KeyType::Root)->first();

    expect($rootKey)->not->toBeNull()
        ->and($rootKey->status)->toBe(KeyStatus::Active);
});

test('prompts to create passphrase when environment variable is missing', function () {
    $originalEnvKey = config('licensing.crypto.keystore.passphrase_env');
    $originalPassphrase = $_ENV[$originalEnvKey] ?? null;
    $temporaryEnvKey = 'LICENSING_KEY_PASSPHRASE_PROMPT_TEST';

    config()->set('licensing.crypto.keystore.passphrase', null);
    config()->set('licensing.crypto.keystore.passphrase_env', $temporaryEnvKey);
    unset($_ENV[$temporaryEnvKey]);
    putenv($temporaryEnvKey);
    LicensingKey::forgetCachedPassphrase();

    try {
        $this->artisan('licensing:keys:make-root')
            ->expectsOutput("Passphrase environment variable {$temporaryEnvKey} not set.")
            ->expectsOutput('A passphrase is required to encrypt generated keys.')
            ->expectsQuestion('Create a new passphrase', 'new-passphrase-123')
            ->expectsQuestion('Confirm passphrase', 'new-passphrase-123')
            ->expectsOutputToContain('Passphrase set for this run.')
            ->expectsOutput('Generating root key pair...')
            ->assertSuccessful();
    } finally {
        config()->set('licensing.crypto.keystore.passphrase_env', $originalEnvKey);
        config()->set('licensing.crypto.keystore.passphrase', null);
        LicensingKey::forgetCachedPassphrase();

        if ($originalPassphrase !== null) {
            $_ENV[$originalEnvKey] = $originalPassphrase;
            putenv($originalEnvKey.'='.$originalPassphrase);
        } else {
            unset($_ENV[$originalEnvKey]);
            putenv($originalEnvKey);
        }

        unset($_ENV[$temporaryEnvKey]);
        putenv($temporaryEnvKey);
    }
});

test('returns error silently when missing passphrase and silent flag used', function () {
    $originalEnvKey = config('licensing.crypto.keystore.passphrase_env');
    $originalPassphrase = $_ENV[$originalEnvKey] ?? null;
    $temporaryEnvKey = 'LICENSING_KEY_PASSPHRASE_SILENT_TEST';

    config()->set('licensing.crypto.keystore.passphrase', null);
    config()->set('licensing.crypto.keystore.passphrase_env', $temporaryEnvKey);
    unset($_ENV[$temporaryEnvKey]);
    putenv($temporaryEnvKey);
    LicensingKey::forgetCachedPassphrase();

    try {
        $this->artisan('licensing:keys:make-root', ['--silent' => true])
            ->assertFailed();

        expect(LicensingKey::findActiveRoot())->toBeNull();
    } finally {
        config()->set('licensing.crypto.keystore.passphrase_env', $originalEnvKey);
        config()->set('licensing.crypto.keystore.passphrase', null);
        LicensingKey::forgetCachedPassphrase();

        if ($originalPassphrase !== null) {
            $_ENV[$originalEnvKey] = $originalPassphrase;
            putenv($originalEnvKey.'='.$originalPassphrase);
        } else {
            unset($_ENV[$originalEnvKey]);
            putenv($originalEnvKey);
        }

        unset($_ENV[$temporaryEnvKey]);
        putenv($temporaryEnvKey);
    }
});

test('cannot create duplicate root key', function () {
    $this->createRootKey();

    $this->artisan('licensing:keys:make-root')
        ->expectsOutput('Active root key already exists. Use --force to replace.')
        ->assertFailed();
});

test('can force replace root key', function () {
    $oldRoot = $this->createRootKey();

    $this->artisan('licensing:keys:make-root', ['--force' => true])
        ->expectsConfirmation('This will revoke the existing root key. Continue?', 'yes')
        ->expectsOutputToContain('Revoking existing root key')
        ->expectsOutputToContain('Root key generated successfully')
        ->assertSuccessful();

    $oldRoot->refresh();
    expect($oldRoot->status)->toBe(KeyStatus::Revoked);
});

test('can issue signing key via CLI', function () {
    $this->createRootKey();

    $this->artisan('licensing:keys:issue-signing', [
        '--kid' => 'test-signing-001',
        '--days' => 90,
    ])
        ->expectsOutput('Generating signing key pair...')
        ->expectsOutputToContain('Signing key issued successfully')
        ->expectsOutputToContain('Key ID: test-signing-001')
        ->expectsOutputToContain('Valid for: 90 days')
        ->assertSuccessful();

    $signingKey = LicensingKey::where('kid', 'test-signing-001')->first();

    expect($signingKey)->not->toBeNull()
        ->and($signingKey->type)->toBe(KeyType::Signing)
        ->and(abs($signingKey->valid_until->diffInDays($signingKey->valid_from)))->toEqual(90);
});

test('cannot issue signing key without root', function () {
    $this->artisan('licensing:keys:issue-signing')
        ->expectsOutput('No active root key found. Run licensing:keys:make-root first.')
        ->assertFailed();
});

test('can rotate signing keys via CLI', function () {
    $this->createRootKey();
    $oldSigning = $this->createSigningKey();

    $this->artisan('licensing:keys:rotate', [
        '--reason' => 'routine',
    ])
        ->expectsOutput('Rotating signing key...')
        ->expectsOutputToContain('Current signing key revoked')
        ->expectsOutputToContain('New signing key issued')
        ->assertSuccessful();

    $oldSigning->refresh();
    expect($oldSigning->status)->toBe(KeyStatus::Revoked)
        ->and($oldSigning->revocation_reason)->toBe('routine');

    $newSigning = LicensingKey::where('type', KeyType::Signing)
        ->where('status', KeyStatus::Active)
        ->first();

    expect($newSigning)->not->toBeNull()
        ->and($newSigning->id)->not->toBe($oldSigning->id);
});

test('can revoke key via CLI', function () {
    $this->createRootKey();
    $signingKey = $this->createSigningKey();

    $this->artisan('licensing:keys:revoke', [
        'kid' => $signingKey->kid,
        '--reason' => 'compromised',
    ])
        ->expectsConfirmation('Are you sure you want to revoke key '.$signingKey->kid.'?', 'yes')
        ->expectsOutputToContain('Key revoked successfully')
        ->assertSuccessful();

    $signingKey->refresh();
    expect($signingKey->status)->toBe(KeyStatus::Revoked)
        ->and($signingKey->revocation_reason)->toBe('compromised');
});

test('can list keys via CLI', function () {
    $rootKey = $this->createRootKey();
    $signingKey = $this->createSigningKey();

    $this->artisan('licensing:keys:list')
        ->expectsTable(
            ['Type', 'KID', 'Status', 'Valid From', 'Valid Until', 'Revoked At'],
            [
                ['root', $rootKey->kid, 'active', $rootKey->valid_from->format('Y-m-d'), 'perpetual', '-'],
                ['signing', $signingKey->kid, 'active', $signingKey->valid_from->format('Y-m-d'), $signingKey->valid_until->format('Y-m-d'), '-'],
            ]
        )
        ->assertSuccessful();
});

test('can export keys in different formats', function () {
    $rootKey = $this->createRootKey();
    $signingKey = $this->createSigningKey();

    // Ensure keys are active
    expect($rootKey->isActive())->toBeTrue();
    expect($signingKey->isActive())->toBeTrue();

    // Export as JWKS
    $this->artisan('licensing:keys:export', [
        '--format' => 'jwks',
    ])
        ->expectsOutputToContain('"keys":')
        ->assertSuccessful();

    // Export as PEM (will fall back to JSON)
    $this->artisan('licensing:keys:export', [
        '--format' => 'pem',
    ])
        ->expectsOutputToContain('PEM format is not applicable')
        ->assertSuccessful();

    // Export as JSON bundle
    $result = $this->artisan('licensing:keys:export', [
        '--format' => 'json',
        '--include-chain' => true,
    ]);

    // Just check that it runs successfully and contains root
    $result->expectsOutputToContain('"root":')
        ->assertSuccessful();
});

test('can issue offline token via CLI', function () {
    $this->createRootKey();
    $this->createSigningKey();
    $license = $this->createLicense();
    $usage = $this->createUsage($license);

    $this->artisan('licensing:offline:issue', [
        '--license' => $license->id,
        '--fingerprint' => $usage->usage_fingerprint,
        '--ttl' => '3d',
    ])
        ->expectsOutputToContain('Offline token issued successfully')
        ->expectsOutputToContain('Token:')
        ->expectsOutputToContain('v4.public.')
        ->assertSuccessful();
});

test('validates license exists for token issuance', function () {
    $this->createRootKey();
    $this->createSigningKey();

    $this->artisan('licensing:offline:issue', [
        '--license' => 'non-existent',
        '--fingerprint' => 'test-fp',
    ])
        ->expectsOutput('License not found: non-existent')
        ->assertFailed();
});

test('validates usage exists for token issuance', function () {
    $this->createRootKey();
    $this->createSigningKey();
    $license = $this->createLicense();

    $this->artisan('licensing:offline:issue', [
        '--license' => $license->id,
        '--fingerprint' => 'non-existent-fp',
    ])
        ->expectsOutput('No active usage found for fingerprint: non-existent-fp')
        ->assertFailed();
});

test('can issue token by license key', function () {
    $this->createRootKey();
    $this->createSigningKey();

    $licenseKey = 'TEST-LICENSE-KEY-123';
    $license = $this->createLicense([
        'key_hash' => \LucaLongo\Licensing\Models\License::hashKey($licenseKey),
    ]);
    $usage = $this->createUsage($license);

    $this->artisan('licensing:offline:issue', [
        '--license' => $licenseKey,
        '--fingerprint' => $usage->usage_fingerprint,
    ])
        ->expectsOutputToContain('Offline token issued successfully')
        ->assertSuccessful();
});

test('handles compromised key rotation', function () {
    $this->createRootKey();
    $signingKey = $this->createSigningKey();

    $this->artisan('licensing:keys:rotate', [
        '--reason' => 'compromised',
        '--immediate' => true,
    ])
        ->expectsOutput('SECURITY: Rotating compromised key immediately...')
        ->expectsOutputToContain('All tokens signed with the compromised key are now invalid')
        ->expectsOutputToContain('Clients must refresh their tokens immediately')
        ->assertSuccessful();

    $signingKey->refresh();
    expect($signingKey->revocation_reason)->toBe('compromised');
});

test('respects verbose output flag', function () {
    $this->createRootKey();

    $this->artisan('licensing:keys:issue-signing', [
        '--verbose' => true,
    ])
        ->expectsOutputToContain('Generating RSA key pair')
        ->expectsOutputToContain('Creating certificate')
        ->expectsOutputToContain('Signing certificate with root key')
        ->expectsOutputToContain('Storing key in keystore')
        ->assertSuccessful();
});

test('command return codes follow spec', function () {
    // Success
    $this->createRootKey();
    $result = Artisan::call('licensing:keys:list');
    expect($result)->toBe(0);

    // Invalid arguments
    $result = Artisan::call('licensing:keys:issue-signing', ['--days' => -1]);
    expect($result)->toBe(1);

    // Not found
    $result = Artisan::call('licensing:keys:revoke', ['kid' => 'non-existent']);
    expect($result)->toBe(2);

    // Revoked/compromised
    $key = $this->createSigningKey();
    $key->revoke('compromised');
    $license = $this->createActiveLicense();
    $usage = $this->createUsage($license);

    // Verify license has a key
    expect($license->key)->not->toBeNull();

    $result = Artisan::call('licensing:offline:issue', [
        '--license' => $license->key,
        '--fingerprint' => $usage->usage_fingerprint,
    ]);
    expect($result)->toBe(3);
});
