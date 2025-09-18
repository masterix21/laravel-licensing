<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LucaLongo\Licensing\Commands\ExportKeysCommand;
use LucaLongo\Licensing\Commands\IssueOfflineTokenCommand;
use LucaLongo\Licensing\Commands\IssueSigningKeyCommand;
use LucaLongo\Licensing\Commands\ListKeysCommand;
use LucaLongo\Licensing\Commands\MakeRootKeyCommand;
use LucaLongo\Licensing\Commands\RotateKeysCommand;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Tests\Helpers\LicenseTestHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

uses(LicenseTestHelper::class)->group('cli');

function runCommand(string $commandClass, array $parameters = [], array $inputs = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): CommandTester
{
    $command = app($commandClass);
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    if ($inputs !== []) {
        $tester->setInputs($inputs);
    }

    $tester->execute($parameters, [
        'interactive' => true,
        'verbosity' => $verbosity,
    ]);

    return $tester;
}

beforeEach(function () {
    // Ensure clean key storage
    $keyPath = config('licensing.crypto.keystore.path');
    if (File::exists($keyPath)) {
        File::deleteDirectory($keyPath);
    }

    // Clear any cached data
    LicensingKey::forgetCachedPassphrase();

    // Reset the passphrase to the test default
    $_ENV['LICENSING_KEY_PASSPHRASE'] = 'test-passphrase-for-testing';
});

afterEach(function () {
    // Clean up after each test
    $keyPath = config('licensing.crypto.keystore.path');
    if (File::exists($keyPath)) {
        File::deleteDirectory($keyPath);
    }

    // Clear any cached data
    LicensingKey::forgetCachedPassphrase();
});

test('can make root key via CLI', function () {
    $tester = runCommand(MakeRootKeyCommand::class);

    expect($tester->getStatusCode())->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain('Generating root key pair...');
    expect($display)->toContain('Root key generated successfully');
    expect($display)->toContain('Key ID:');
    expect($display)->toContain('Public key bundle exported to:');

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
        $tester = runCommand(MakeRootKeyCommand::class, [], ['new-passphrase-123', 'new-passphrase-123']);

        expect($tester->getStatusCode())->toBe(0);

        $display = $tester->getDisplay();
        expect($display)->toContain("Passphrase environment variable {$temporaryEnvKey} not set.");
        expect($display)->toContain('A passphrase is required to encrypt generated keys.');
        expect($display)->toContain('Passphrase set for this run.');
        expect($display)->toContain('Generating root key pair...');
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
        $tester = runCommand(MakeRootKeyCommand::class, ['--silent' => true]);

        expect($tester->getStatusCode())->not->toBe(0);
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
    // Ensure clean state
    LicensingKey::where('type', \LucaLongo\Licensing\Enums\KeyType::Root)->delete();

    $this->createRootKey();

    $tester = runCommand(MakeRootKeyCommand::class);

    expect($tester->getStatusCode())->not->toBe(0);
    expect($tester->getDisplay())->toContain('Active root key already exists. Use --force to replace.');
});

test('can force replace root key', function () {
    $oldRoot = $this->createRootKey();

    $tester = runCommand(MakeRootKeyCommand::class, ['--force' => true], ['yes']);

    expect($tester->getStatusCode())->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain('Revoking existing root key');
    expect($display)->toContain('Root key generated successfully');

    $oldRoot->refresh();
    expect($oldRoot->status)->toBe(KeyStatus::Revoked);
});

test('can issue signing key via CLI', function () {
    $this->createRootKey();

    $tester = runCommand(IssueSigningKeyCommand::class, [
        '--kid' => 'test-signing-001',
        '--days' => 90,
    ]);

    expect($tester->getStatusCode())->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain('Generating signing key pair');
    expect($display)->toContain('Signing key issued successfully');
    expect($display)->toContain('Key ID: test-signing-001');
    expect($display)->toContain('Valid for: 90 days');

    $signingKey = LicensingKey::where('kid', 'test-signing-001')->first();

    expect($signingKey)->not->toBeNull()
        ->and($signingKey->type)->toBe(KeyType::Signing)
        ->and(abs($signingKey->valid_until->diffInDays($signingKey->valid_from)))->toEqual(90);
});

test('cannot issue signing key without root', function () {
    // Explicitly ensure no root key exists
    LicensingKey::where('type', \LucaLongo\Licensing\Enums\KeyType::Root)->delete();

    $tester = runCommand(IssueSigningKeyCommand::class);

    expect($tester->getStatusCode())->not->toBe(0);
    expect($tester->getDisplay())->toContain('No active root key found. Run licensing:keys:make-root first.');
});

test('can rotate signing keys via CLI', function () {
    $this->createRootKey();
    $oldSigning = $this->createSigningKey();

    $tester = runCommand(RotateKeysCommand::class, [
        '--reason' => 'routine',
    ]);

    expect($tester->getStatusCode())->toBe(0);
    $display = $tester->getDisplay();
    expect($display)->toContain('Rotating signing key');
    expect($display)->toContain('Current signing key revoked');
    expect($display)->toContain('New signing key issued');

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

    $tester = runCommand(\LucaLongo\Licensing\Commands\RevokeKeyCommand::class, [
        'kid' => $signingKey->kid,
        '--reason' => 'compromised',
    ], ['yes']);

    expect($tester->getStatusCode())->toBe(0);
    expect($tester->getDisplay())->toContain('Key revoked successfully');

    $signingKey->refresh();
    expect($signingKey->status)->toBe(KeyStatus::Revoked)
        ->and($signingKey->revocation_reason)->toBe('compromised');
});

test('can list keys via CLI', function () {
    $rootKey = $this->createRootKey();
    $signingKey = $this->createSigningKey();

    $command = app(ListKeysCommand::class);
    $command->setLaravel($this->app);
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([]);

    expect($exitCode)->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain($rootKey->kid);
    expect($display)->toContain($signingKey->kid);
    expect($display)->toContain('root');
    expect($display)->toContain('signing');
});

test('can export keys in different formats', function () {
    $rootKey = $this->createRootKey();
    $signingKey = $this->createSigningKey();

    // Ensure keys are active
    expect($rootKey->isActive())->toBeTrue();
    expect($signingKey->isActive())->toBeTrue();

    // Export as JWKS
    $jwks = runCommand(ExportKeysCommand::class, ['--format' => 'jwks']);
    expect($jwks->getStatusCode())->toBe(0);
    expect($jwks->getDisplay())->toContain('"keys":');

    // Export as PEM (will fall back to JSON)
    $pem = runCommand(ExportKeysCommand::class, ['--format' => 'pem']);
    expect($pem->getStatusCode())->toBe(0);
    expect($pem->getDisplay())->toContain('PEM format is not applicable');

    // Export as JSON bundle
    $json = runCommand(ExportKeysCommand::class, [
        '--format' => 'json',
        '--include-chain' => true,
    ]);

    expect($json->getStatusCode())->toBe(0);
    expect($json->getDisplay())->toContain('"root":');
});

test('can issue offline token via CLI', function () {
    $this->createRootKey();
    $this->createSigningKey();
    $license = $this->createLicense();
    $usage = $this->createUsage($license);

    $tester = runCommand(IssueOfflineTokenCommand::class, [
        '--license' => (string) $license->id,
        '--fingerprint' => $usage->usage_fingerprint,
        '--ttl' => '3d',
    ]);

    expect($tester->getStatusCode())->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain('Offline token issued successfully');
    expect($display)->toContain('Token:');
    expect($display)->toContain('v4.public.');
});

test('validates license exists for token issuance', function () {
    $this->createRootKey();
    $this->createSigningKey();

    $tester = runCommand(IssueOfflineTokenCommand::class, [
        '--license' => 'non-existent',
        '--fingerprint' => 'test-fp',
    ]);

    expect($tester->getStatusCode())->not->toBe(0);
    expect($tester->getDisplay())->toContain('License not found: non-existent');
});

test('validates usage exists for token issuance', function () {
    $this->createRootKey();
    $this->createSigningKey();
    $license = $this->createLicense();

    $tester = runCommand(IssueOfflineTokenCommand::class, [
        '--license' => (string) $license->id,
        '--fingerprint' => 'non-existent-fp',
    ]);

    expect($tester->getStatusCode())->not->toBe(0);
    expect($tester->getDisplay())->toContain('No active usage found for fingerprint: non-existent-fp');
});

test('can issue token by license key', function () {
    $this->createRootKey();
    $this->createSigningKey();

    $licenseKey = 'TEST-LICENSE-KEY-123';
    $license = $this->createLicense([
        'key_hash' => \LucaLongo\Licensing\Models\License::hashKey($licenseKey),
    ]);
    $usage = $this->createUsage($license);

    $tester = runCommand(IssueOfflineTokenCommand::class, [
        '--license' => $licenseKey,
        '--fingerprint' => $usage->usage_fingerprint,
    ]);

    expect($tester->getStatusCode())->toBe(0);
    expect($tester->getDisplay())->toContain('Offline token issued successfully');
});

test('handles compromised key rotation', function () {
    $this->createRootKey();
    $signingKey = $this->createSigningKey();

    $tester = runCommand(RotateKeysCommand::class, [
        '--reason' => 'compromised',
        '--immediate' => true,
    ]);

    expect($tester->getStatusCode())->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain('SECURITY: Rotating compromised key immediately...');
    expect($display)->toContain('All tokens signed with the compromised key are now invalid');
    expect($display)->toContain('Clients must refresh their tokens immediately');

    $signingKey->refresh();
    expect($signingKey->revocation_reason)->toBe('compromised');
});

test('respects verbose output flag', function () {
    $this->createRootKey();

    $tester = runCommand(IssueSigningKeyCommand::class, [], [], OutputInterface::VERBOSITY_VERBOSE);

    expect($tester->getStatusCode())->toBe(0);

    $display = $tester->getDisplay();
    expect($display)->toContain('Generating RSA key pair');
    expect($display)->toContain('Creating certificate');
    expect($display)->toContain('Signing certificate with root key');
    expect($display)->toContain('Storing key in keystore');
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
