<?php

// Regression test for: https://github.com/masterix21/laravel-licensing/issues/3
//
// Root cause: resolvePassphrase() read the passphrase via $_ENV/$_SERVER/getenv() at runtime.
// After `artisan config:cache` Laravel disables direct env access, so the passphrase resolved
// to null and every key operation threw "Key passphrase not configured".
//
// Fix: config/licensing.php now stores `passphrase => env('LICENSING_KEY_PASSPHRASE')` so the
// value is resolved once at cache time, and resolvePassphrase() reads only from config().

use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicensingKey;

beforeEach(function () {
    LicensingKey::forgetCachedPassphrase();
    config()->set('licensing.crypto.keystore.passphrase', 'test-passphrase-for-testing');
});

afterEach(function () {
    LicensingKey::forgetCachedPassphrase();
});

test('resolvePassphrase works when passphrase is in config and env superglobals are unavailable', function () {
    // Simulate the post-optimize environment: superglobals are completely cleared.
    $original = $_ENV['LICENSING_KEY_PASSPHRASE'] ?? null;
    unset($_ENV['LICENSING_KEY_PASSPHRASE'], $_SERVER['LICENSING_KEY_PASSPHRASE']);
    putenv('LICENSING_KEY_PASSPHRASE=');

    try {
        // Act: generating a key must succeed because the passphrase comes from config().
        $key = (new LicensingKey)->generate(['type' => KeyType::Root]);

        // Assert
        expect($key)->toBeInstanceOf(LicensingKey::class)
            ->and($key->public_key)->not->toBeEmpty()
            ->and($key->private_key_encrypted)->not->toBeEmpty();
    } finally {
        if ($original !== null) {
            $_ENV['LICENSING_KEY_PASSPHRASE'] = $original;
            putenv("LICENSING_KEY_PASSPHRASE={$original}");
        }
    }
});

test('resolvePassphrase throws when passphrase is not configured', function () {
    config()->set('licensing.crypto.keystore.passphrase', null);
    LicensingKey::forgetCachedPassphrase();

    expect(fn () => (new LicensingKey)->generate(['type' => KeyType::Root]))
        ->toThrow(RuntimeException::class, 'Key passphrase not configured');
});

test('encrypt v2 and decrypt v2 round-trip', function () {
    $key = (new LicensingKey)->generate(['type' => KeyType::Root]);

    $privateKey = $key->getPrivateKey();

    expect($privateKey)->not->toBeNull()
        ->and($privateKey)->not->toBeEmpty();

    // Verify v2 format: base64-decoded starts with \x02
    $decoded = base64_decode($key->private_key_encrypted);
    expect($decoded[0])->toBe("\x02");
});

test('decrypt v1 legacy format for backward compatibility', function () {
    // Manually encrypt with v1 format (SHA-256 single round)
    $passphrase = 'test-passphrase-for-testing';
    $plaintext = base64_encode(random_bytes(32));

    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $derivedKey = hash('sha256', $passphrase, true);
    $encrypted = sodium_crypto_secretbox($plaintext, $nonce, $derivedKey);
    $v1Payload = base64_encode($nonce.$encrypted);

    sodium_memzero($derivedKey);

    // Create a key model with v1 encrypted data
    $key = new LicensingKey;
    $key->kid = 'test_v1_'.now()->timestamp;
    $key->type = KeyType::Root;
    $key->algorithm = 'Ed25519';
    $key->public_key = base64_encode(random_bytes(32));
    $key->private_key_encrypted = $v1Payload;
    $key->status = \LucaLongo\Licensing\Enums\KeyStatus::Active;
    $key->valid_from = now();
    $key->save();

    $decrypted = $key->getPrivateKey();

    expect($decrypted)->toBe($plaintext);
});

test('decrypt fails with wrong passphrase', function () {
    $key = (new LicensingKey)->generate(['type' => KeyType::Root]);

    // Change passphrase
    LicensingKey::forgetCachedPassphrase();
    config()->set('licensing.crypto.keystore.passphrase', 'wrong-passphrase-entirely');

    expect(fn () => $key->getPrivateKey())
        ->toThrow(RuntimeException::class, 'Failed to decrypt private key');
});

test('sodium_memzero cleanup does not cause errors on repeated operations', function () {
    $key = (new LicensingKey)->generate(['type' => KeyType::Root]);

    // Multiple decrypt operations should work without issues
    $first = $key->getPrivateKey();
    $second = $key->getPrivateKey();

    expect($first)->toBe($second);
});

test('forgetCachedPassphrase clears the static cache', function () {
    LicensingKey::cachePassphrase('cached-value');

    LicensingKey::forgetCachedPassphrase();

    // Remove config passphrase to verify cache was cleared
    config()->set('licensing.crypto.keystore.passphrase', null);

    expect(fn () => (new LicensingKey)->generate(['type' => KeyType::Root]))
        ->toThrow(RuntimeException::class, 'Key passphrase not configured');
});
