<?php

namespace LucaLongo\Licensing\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\AuditLoggerService;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

class RotateKeysCommand extends Command
{
    protected $signature = 'licensing:keys:rotate 
                            {--reason=routine : Reason for rotation (routine|compromised)}';

    protected $description = 'Rotate the current signing key by revoking it and issuing a new one';

    public function handle(
        AuditLoggerService $auditLogger,
        CertificateAuthorityService $ca
    ): int {
        $reason = $this->option('reason');

        if (! in_array($reason, ['routine', 'compromised'])) {
            $this->error('Invalid reason. Must be "routine" or "compromised".');

            return 1;
        }

        $currentSigningKey = LicensingKey::findActiveSigning();

        if (! $currentSigningKey) {
            $this->warn('No active signing key found. Issuing new one...');
        } else {
            $this->info('Revoking current signing key: '.$currentSigningKey->kid);

            $revokedAt = $reason === 'compromised'
                ? now()->subHour() // Backdate for compromised keys
                : now();

            $currentSigningKey->revoke($reason, $revokedAt);

            $this->info('Current signing key revoked.');
        }

        $this->info('Issuing new signing key...');

        try {
            $validFrom = new DateTimeImmutable;
            $validUntil = $validFrom->modify('+30 days');
            $kid = 'kid_'.bin2hex(random_bytes(16));

            $signingKey = new LicensingKey;
            $signingKey->generate([
                'type' => KeyType::Signing,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ]);

            $signingKey->kid = $kid;
            $signingKey->save();

            $certificate = $ca->issueSigningCertificate(
                $signingKey->getPublicKey(),
                $kid,
                $validFrom,
                $validUntil
            );

            $signingKey->update(['certificate' => $certificate]);

            $publicBundlePath = config('licensing.publishing.public_bundle_path');
            $bundle = [
                'signing' => [
                    'kid' => $signingKey->kid,
                    'public_key' => $signingKey->getPublicKey(),
                    'certificate' => $certificate,
                    'valid_from' => $validFrom->format('c'),
                    'valid_until' => $validUntil->format('c'),
                ],
                'root' => [
                    'kid' => LicensingKey::findActiveRoot()->kid,
                    'public_key' => $ca->getRootPublicKey(),
                ],
                'rotated_at' => now()->format('c'),
                'reason' => $reason,
            ];

            file_put_contents($publicBundlePath, json_encode($bundle, JSON_PRETTY_PRINT));

            $auditLogger->log(
                AuditEventType::KeyRotated,
                [
                    'old_kid' => $currentSigningKey?->kid,
                    'new_kid' => $kid,
                    'reason' => $reason,
                ],
                'console'
            );

            $this->info('Key rotation completed successfully!');
            $this->line('');
            $this->line('New Key ID: '.$kid);
            $this->line('Reason: '.$reason);
            $this->line('Public bundle updated: '.$publicBundlePath);

            if ($reason === 'compromised') {
                $this->warn('IMPORTANT: All tokens signed with the compromised key are now invalid.');
                $this->warn('Clients must update their public key bundle immediately.');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to rotate keys: '.$e->getMessage());

            return 3;
        }
    }
}
