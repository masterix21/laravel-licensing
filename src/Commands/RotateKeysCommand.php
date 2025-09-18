<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

class RotateKeysCommand extends Command
{
    protected $signature = 'licensing:keys:rotate 
        {--reason=routine : Rotation reason (routine|compromised)}
        {--immediate : Immediately revoke old key (for compromised keys)}';

    protected $description = 'Rotate signing keys';

    public function handle(CertificateAuthorityService $ca): int
    {
        $reason = $this->option('reason');
        $immediate = $this->option('immediate');

        if (! in_array($reason, ['routine', 'compromised'])) {
            $this->line('Invalid reason. Must be "routine" or "compromised".');

            return 1;
        }

        if ($reason === 'compromised' && $immediate) {
            $this->line('SECURITY: Rotating compromised key immediately...');
        }

        $rootKey = LicensingKey::findActiveRoot();
        if (! $rootKey) {
            $this->line('No active root key found.');

            return 2;
        }

        $this->line('Rotating signing key...');

        $currentSigningKey = LicensingKey::findActiveSigning();

        if ($currentSigningKey) {
            $this->line('Current signing key revoked');
            $currentSigningKey->revoke($reason);
        }

        $this->line('Generating new signing key...');

        $newKid = 'signing-'.uniqid();
        $newSigningKey = LicensingKey::generateSigningKey($newKid);
        $newSigningKey->valid_from = now();
        $newSigningKey->valid_until = now()->addDays(30);

        // Issue certificate
        $certificate = $ca->issueSigningCertificate(
            $newSigningKey->getPublicKey(),
            $newSigningKey->kid,
            $newSigningKey->valid_from,
            $newSigningKey->valid_until
        );
        $newSigningKey->certificate = $certificate;

        $newSigningKey->save();

        $this->line('New signing key issued');
        $this->line('Key ID: '.$newKid);

        if ($reason === 'compromised') {
            $this->line('All tokens signed with the compromised key are now invalid');
            $this->line('Clients must refresh their tokens immediately');
            $this->line('IMPORTANT: Update all clients immediately with the new public key bundle.');
        }

        return 0;
    }
}
