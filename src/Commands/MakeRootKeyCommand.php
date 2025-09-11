<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;

class MakeRootKeyCommand extends Command
{
    protected $signature = 'licensing:keys:make-root {--force : Force creation even if root exists}';

    protected $description = 'Generate a new root key pair';

    public function handle(): int
    {
        $existingRoot = LicensingKey::findActiveRoot();

        if ($existingRoot) {
            if (! $this->option('force')) {
                $this->error('Active root key already exists. Use --force to replace.');

                return 1;
            }

            if (! $this->confirm('This will revoke the existing root key. Continue?')) {
                return 0;
            }

            $this->info('Revoking existing root key...');
            $existingRoot->revoke('replaced');
        }

        $this->info('Generating root key pair...');

        $passphrase = env(config('licensing.crypto.keystore.passphrase_env'));
        if (! $passphrase) {
            $this->error('Passphrase environment variable not set.');

            return 3;
        }

        $rootKey = LicensingKey::generateRootKey();

        $this->info('Root key generated successfully.');
        $this->info('Key ID: '.$rootKey->kid);
        $this->info('Public key bundle exported to: '.$this->getPublicBundlePath());
        $this->warn('IMPORTANT: Back up your private key and passphrase securely!');

        return 0;
    }

    private function getPublicBundlePath(): string
    {
        return config('licensing.publishing.public_bundle_path', storage_path('app/licensing/public-bundle.json'));
    }
}
