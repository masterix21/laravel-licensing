<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;

class MakeRootKeyCommand extends Command
{
    protected $signature = 'licensing:keys:make-root {--force : Force creation even if root exists} {--silent : Do not prompt for missing passphrase}';

    protected $description = 'Generate a new root key pair';

    public function handle(): int
    {
        $existingRoot = LicensingKey::findActiveRoot();

        if ($existingRoot) {
            if (! $this->option('force')) {
                $this->line('Active root key already exists. Use --force to replace.');

                return 1;
            }

            if (! $this->confirm('This will revoke the existing root key. Continue?')) {
                return 0;
            }

            $this->line('Revoking existing root key...');
            $existingRoot->revoke('replaced');
        }

        if (! $this->ensurePassphrase()) {
            return 3;
        }

        $this->line('Generating root key pair...');

        $rootKey = LicensingKey::generateRootKey();

        $this->line('Root key generated successfully');
        $this->line('Key ID: '.$rootKey->kid);
        $this->line('Public key bundle exported to: '.$this->getPublicBundlePath());
        $this->line('IMPORTANT: Back up your private key and passphrase securely!');

        return 0;
    }

    private function getPublicBundlePath(): string
    {
        return config('licensing.publishing.public_bundle_path', storage_path('app/licensing/public-bundle.json'));
    }

    private function ensurePassphrase(): bool
    {
        $envKey = config('licensing.crypto.keystore.passphrase_env');
        $passphrase = env($envKey);

        if ($passphrase) {
            LicensingKey::cachePassphrase($passphrase);

            return true;
        }

        $isSilent = (bool) $this->option('silent');

        if ($this->input->hasOption('no-interaction')) {
            $isSilent = $isSilent || (bool) $this->input->getOption('no-interaction');
        }

        if ($isSilent) {
            return false;
        }

        $this->line("Passphrase environment variable {$envKey} not set.");
        $this->line('A passphrase is required to encrypt generated keys.');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $passphrase = (string) $this->secret('Create a new passphrase');

            if ($passphrase === '') {
                $this->line('Passphrase cannot be empty.');

                continue;
            }

            $confirmation = (string) $this->secret('Confirm passphrase');

            if ($passphrase !== $confirmation) {
                $this->line('Passphrases do not match.');

                continue;
            }

            config()->set('licensing.crypto.keystore.passphrase', $passphrase);
            LicensingKey::cachePassphrase($passphrase);

            $this->line('Passphrase set for this run.');

            return true;
        }

        $this->line('Failed to capture passphrase.');

        return false;
    }
}
