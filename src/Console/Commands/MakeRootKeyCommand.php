<?php

namespace LucaLongo\Licensing\Console\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\AuditLoggerService;

class MakeRootKeyCommand extends Command
{
    protected $signature = 'licensing:keys:make-root 
                            {--force : Overwrite existing root key}';

    protected $description = 'Generate a new root keypair for the licensing system';

    public function handle(AuditLoggerService $auditLogger): int
    {
        $existingRoot = LicensingKey::findActiveRoot();

        if ($existingRoot && ! $this->option('force')) {
            $this->error('An active root key already exists. Use --force to overwrite.');

            return 1;
        }

        if ($existingRoot && $this->option('force')) {
            if (! $this->confirm('This will revoke the existing root key. Continue?')) {
                return 1;
            }

            $existingRoot->revoke('replaced', now());
            $this->info('Existing root key revoked.');
        }

        $this->info('Generating root key pair...');

        try {
            $rootKey = new LicensingKey;
            $rootKey->generate(['type' => KeyType::Root]);

            $publicBundlePath = config('licensing.publishing.public_bundle_path');
            $publicBundle = [
                'root' => [
                    'kid' => $rootKey->kid,
                    'public_key' => $rootKey->getPublicKey(),
                    'valid_from' => $rootKey->valid_from->format('c'),
                    'valid_until' => $rootKey->valid_until?->format('c'),
                ],
                'issued_at' => now()->format('c'),
            ];

            if (! is_dir(dirname($publicBundlePath))) {
                mkdir(dirname($publicBundlePath), 0755, true);
            }

            file_put_contents($publicBundlePath, json_encode($publicBundle, JSON_PRETTY_PRINT));

            $auditLogger->log(
                AuditEventType::KeyRootGenerated,
                ['kid' => $rootKey->kid],
                'console'
            );

            $this->info('Root key generated successfully!');
            $this->line('');
            $this->line('Key ID: '.$rootKey->kid);
            $this->line('Public key bundle exported to: '.$publicBundlePath);
            $this->line('');
            $this->warn('IMPORTANT: Back up your key passphrase and private keys securely!');

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to generate root key: '.$e->getMessage());

            return 3;
        }
    }
}
