<?php

use Illuminate\Support\Facades\Crypt;
use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRegeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRetrieverContract;
use LucaLongo\Licensing\Models\License;

it('can generate a license key', function () {
    $generator = app(LicenseKeyGeneratorContract::class);
    $key = $generator->generate();

    expect($key)->toBeString()
        ->toStartWith('LIC-')
        ->toMatch('/^LIC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/');
});

it('can create a license with key', function () {
    $license = License::createWithKey([
        'max_usages' => 5,
    ]);

    expect($license)->toBeInstanceOf(License::class)
        ->and($license->license_key)->toBeString()
        ->and($license->license_key)->toStartWith('LIC-')
        ->and($license->key_hash)->toBeString()
        ->and($license->meta['encrypted_key'])->toBeString();
});

it('can retrieve a license key when enabled', function () {
    config(['licensing.key_management.retrieval_enabled' => true]);

    $license = License::createWithKey();
    $originalKey = $license->license_key;

    // Retrieve the key
    $retrievedKey = $license->retrieveKey();

    expect($retrievedKey)->toBe($originalKey);
});

it('cannot retrieve a license key when disabled', function () {
    config(['licensing.key_management.retrieval_enabled' => false]);

    $license = License::createWithKey();

    expect($license->retrieveKey())->toBeNull()
        ->and($license->canRetrieveKey())->toBeFalse();
});

it('can regenerate a license key', function () {
    config(['licensing.key_management.regeneration_enabled' => true]);

    $license = License::createWithKey();
    $originalKey = $license->license_key;
    $originalHash = $license->key_hash;

    // Regenerate the key
    $newKey = $license->regenerateKey();

    // Refresh the license
    $license->refresh();

    expect($newKey)->toBeString()
        ->not->toBe($originalKey)
        ->and($license->key_hash)->not->toBe($originalHash)
        ->and($license->verifyKey($newKey))->toBeTrue()
        ->and($license->verifyKey($originalKey))->toBeFalse();
});

it('stores previous key hashes when regenerating', function () {
    $license = License::createWithKey();
    $originalHash = $license->key_hash;

    $license->regenerateKey();
    $license->refresh();

    expect($license->meta['previous_key_hashes'])->toBeArray()
        ->and($license->meta['previous_key_hashes'][0]['hash'])->toBe($originalHash)
        ->and($license->meta['previous_key_hashes'][0]['replaced_at'])->toBeString();
});

it('cannot regenerate key when disabled', function () {
    config(['licensing.key_management.regeneration_enabled' => false]);

    $license = License::createWithKey();

    expect($license->canRegenerateKey())->toBeFalse();

    expect(fn () => $license->regenerateKey())
        ->toThrow(RuntimeException::class, 'License key regeneration is not available');
});

it('can verify a license key', function () {
    $license = License::createWithKey();
    $key = $license->license_key;

    expect($license->verifyKey($key))->toBeTrue()
        ->and($license->verifyKey('wrong-key'))->toBeFalse();
});

it('can find a license by key', function () {
    $license = License::createWithKey();
    $key = $license->license_key;

    $found = License::findByKey($key);

    expect($found)->toBeInstanceOf(License::class)
        ->and($found->id)->toBe($license->id);
});

it('returns null when finding non-existent key', function () {
    $found = License::findByKey('non-existent-key');

    expect($found)->toBeNull();
});

it('encrypts stored keys properly', function () {
    $license = License::createWithKey();
    $key = $license->license_key;

    // The encrypted key should be stored
    expect($license->meta['encrypted_key'])->toBeString();

    // And should decrypt to the original key
    $decrypted = Crypt::decryptString($license->meta['encrypted_key']);
    expect($decrypted)->toBe($key);
});

it('generates unique keys', function () {
    $keys = [];

    for ($i = 0; $i < 100; $i++) {
        $license = License::createWithKey();
        $keys[] = $license->license_key;
    }

    $uniqueKeys = array_unique($keys);

    expect(count($uniqueKeys))->toBe(100);
});

it('respects custom key prefix and separator', function () {
    config([
        'licensing.key_management.key_prefix' => 'TEST',
        'licensing.key_management.key_separator' => '_',
    ]);

    $generator = app(LicenseKeyGeneratorContract::class);
    $key = $generator->generate();

    expect($key)->toStartWith('TEST_')
        ->toMatch('/^TEST_[A-Z0-9]{4}_[A-Z0-9]{4}_[A-Z0-9]{4}_[A-Z0-9]{4}$/');
});

it('can create license with provided key', function () {
    $providedKey = 'CUSTOM-KEY-1234-5678';

    $license = License::create([
        'key_hash' => License::hashKey($providedKey),
        'max_usages' => 3,
    ]);

    expect($license->verifyKey($providedKey))->toBeTrue()
        ->and($license->verifyKey('wrong-key'))->toBeFalse()
        ->and($license->retrieveKey())->toBeNull(); // No encrypted key stored
});

it('can create license with provided key and store it encrypted', function () {
    $providedKey = 'CUSTOM-KEY-1234-5678';

    $license = License::create([
        'key_hash' => License::hashKey($providedKey),
        'max_usages' => 3,
        'meta' => [
            'encrypted_key' => Crypt::encryptString($providedKey),
        ],
    ]);

    expect($license->verifyKey($providedKey))->toBeTrue()
        ->and($license->retrieveKey())->toBe($providedKey);
});

it('auto generates key when not provided', function () {
    $license = License::createWithKey([
        'max_usages' => 2,
    ]);

    expect($license->license_key)->toBeString()
        ->and($license->license_key)->toStartWith('LIC-')
        ->and($license->key_hash)->toBeString()
        ->and(License::hashKey($license->license_key))->toBe($license->key_hash);
});

it('retrieves the exact same key that was stored', function () {
    config(['licensing.key_management.retrieval_enabled' => true]);

    $license = License::createWithKey();
    $originalKey = $license->license_key;

    // Simulate reloading from database
    $license = License::find($license->id);

    $retrievedKey = $license->retrieveKey();

    expect($retrievedKey)->toBe($originalKey)
        ->and($license->verifyKey($originalKey))->toBeTrue()
        ->and($license->verifyKey($retrievedKey))->toBeTrue();
});

it('encrypt and decrypt preserve exact key format', function () {
    $testKey = 'TEST-1234-ABCD-5678-EFGH';

    $encrypted = Crypt::encryptString($testKey);
    $decrypted = Crypt::decryptString($encrypted);

    expect($decrypted)->toBe($testKey);

    // Test with actual license
    $license = License::create([
        'key_hash' => License::hashKey($testKey),
        'meta' => [
            'encrypted_key' => $encrypted,
        ],
    ]);

    expect($license->retrieveKey())->toBe($testKey);
});

it('handles special characters in license key', function () {
    $specialKey = 'KEY!@#$%^&*()_+-=[]{}|;:,.<>?';

    $license = License::create([
        'key_hash' => License::hashKey($specialKey),
        'meta' => [
            'encrypted_key' => Crypt::encryptString($specialKey),
        ],
    ]);

    expect($license->verifyKey($specialKey))->toBeTrue()
        ->and($license->retrieveKey())->toBe($specialKey);
});

it('persists encrypted key across database operations', function () {
    $license = License::createWithKey();
    $originalKey = $license->license_key;

    // Force save and reload
    $license->save();
    $license->refresh();

    expect($license->retrieveKey())->toBe($originalKey);

    // Load fresh instance
    $freshLicense = License::find($license->id);
    expect($freshLicense->retrieveKey())->toBe($originalKey);
});

it('maintains key integrity after regeneration', function () {
    $license = License::createWithKey();
    $firstKey = $license->license_key;

    // Verify first key works
    expect($license->verifyKey($firstKey))->toBeTrue()
        ->and($license->retrieveKey())->toBe($firstKey);

    // Regenerate
    $secondKey = $license->regenerateKey();

    // Verify new key works and old doesn't
    expect($license->verifyKey($secondKey))->toBeTrue()
        ->and($license->verifyKey($firstKey))->toBeFalse()
        ->and($license->retrieveKey())->toBe($secondKey);
});

it('createWithKey accepts provided key', function () {
    $customKey = 'MY-CUSTOM-KEY-12345';

    $license = License::createWithKey([
        'max_usages' => 10,
    ], $customKey);

    expect($license->license_key)->toBe($customKey)
        ->and($license->verifyKey($customKey))->toBeTrue()
        ->and($license->retrieveKey())->toBe($customKey)
        ->and($license->key_hash)->toBe(License::hashKey($customKey));
});

it('createWithKey generates key when not provided', function () {
    $license = License::createWithKey([
        'max_usages' => 5,
    ]); // No key provided

    expect($license->license_key)->toBeString()
        ->and($license->license_key)->toStartWith('LIC-')
        ->and($license->verifyKey($license->license_key))->toBeTrue();
});