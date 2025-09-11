<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

class IssueSigningKeyCommand extends Command
{
    protected $signature = 'licensing:keys:issue-signing 
        {--kid= : Key ID} 
        {--days=30 : Validity period in days}
        {--nbf= : Not before date (ISO)}
        {--exp= : Expiration date (ISO)}';
    
    protected $description = 'Issue a new signing key';

    public function handle(CertificateAuthorityService $ca): int
    {
        $rootKey = LicensingKey::findActiveRoot();
        
        if (! $rootKey) {
            $this->error('No active root key found. Run licensing:keys:make-root first.');
            return 2;
        }
        
        $this->info('Generating signing key pair...');
        
        $kid = $this->option('kid') ?? 'signing-' . uniqid();
        $days = (int) $this->option('days');
        $nbf = $this->option('nbf');
        $exp = $this->option('exp');
        $verbose = $this->output->isVerbose();
        
        if ($days <= 0) {
            $this->error('Invalid --days value. Must be greater than 0.');
            return 1;
        }
        
        // Calculate validity period
        $validFrom = $nbf ? new \DateTimeImmutable($nbf) : now();
        $validUntil = $exp ? new \DateTimeImmutable($exp) : now()->addDays($days);
        
        if ($verbose) {
            $this->info('Generating RSA key pair...');
        }
        
        // Generate the signing key with validity period
        $signingKey = new LicensingKey();
        $signingKey->kid = $kid;
        $signingKey->valid_from = $validFrom;
        $signingKey->valid_until = $validUntil;
        $signingKey->generate(['type' => KeyType::Signing]);
        
        if ($verbose) {
            $this->info('Creating certificate...');
            $this->info('Signing certificate with root key...');
        }
        
        // Issue certificate signed by root
        $certificate = $ca->issueSigningCertificate(
            $signingKey->getPublicKey(),
            $signingKey->kid,
            $validFrom,
            $validUntil
        );
        
        if ($verbose) {
            $this->info('Storing key in keystore...');
        }
        
        $signingKey->update(['certificate' => $certificate]);
        
        $this->info('Signing key issued successfully.');
        $this->info("Key ID: {$kid}");
        $this->info("Valid for: {$days} days");
        $this->info("Expires at: {$validUntil->format('Y-m-d H:i:s')}");
        
        return 0;
    }
}