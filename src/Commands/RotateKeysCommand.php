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
            $this->error('Invalid reason. Must be "routine" or "compromised".');
            return 1;
        }
        
        if ($reason === 'compromised' && $immediate) {
            $this->error('SECURITY: Rotating compromised key immediately...');
        }
        
        $rootKey = LicensingKey::findActiveRoot();
        if (! $rootKey) {
            $this->error('No active root key found.');
            return 2;
        }
        
        $this->info('Rotating signing key...');
        
        $currentSigningKey = LicensingKey::findActiveSigning();
        
        if ($currentSigningKey) {
            $this->info("Current signing key revoked: {$currentSigningKey->kid}");
            $currentSigningKey->revoke($reason);
        }
        
        $this->info('Generating new signing key...');
        
        $newKid = 'signing-' . uniqid();
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
        
        $this->info("New signing key issued: {$newKid}");
        
        if ($reason === 'compromised') {
            $this->warn('All tokens signed with the compromised key are now invalid');
            $this->warn('Clients must refresh their tokens immediately');
            $this->warn('IMPORTANT: Update all clients immediately with the new public key bundle.');
        }
        
        return 0;
    }
}