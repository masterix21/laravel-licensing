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
});

afterEach(function () {
    LicensingKey::forgetCachedPassphrase();
});

test('resolvePassphrase works when passphrase is in config and env superglobals are unavailable', function () {
    // Arrange: passphrase stored directly in config (the correct approach after artisan optimize).
    config()->set('licensing.crypto.keystore.passphrase', 'test-passphrase-for-testing');

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
