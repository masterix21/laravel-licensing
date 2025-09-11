<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Services\PasetoTokenService;
use LucaLongo\Licensing\Enums\UsageStatus;

class IssueOfflineTokenCommand extends Command
{
    protected $signature = 'licensing:offline:issue 
        {--license= : License ID or key}
        {--fingerprint= : Usage fingerprint}
        {--ttl=7d : Token TTL}';
    
    protected $description = 'Issue an offline token for a license';

    public function handle(PasetoTokenService $tokenService): int
    {
        $licenseRef = $this->option('license');
        $fingerprint = $this->option('fingerprint');
        $ttl = $this->option('ttl');
        
        if (! $licenseRef || ! $fingerprint) {
            $this->error('Both --license and --fingerprint are required.');
            return 1;
        }
        
        // Find license by ID or key
        $license = is_numeric($licenseRef) 
            ? License::find($licenseRef)
            : License::findByKey($licenseRef);
            
        if (! $license) {
            $this->error("License not found: {$licenseRef}");
            return 2;
        }
        
        // Find or create usage
        $usage = $license->usages()
            ->where('usage_fingerprint', $fingerprint)
            ->where('status', UsageStatus::Active->value)
            ->first();
            
        if (! $usage) {
            $this->error("No active usage found for fingerprint: {$fingerprint}");
            return 2;
        }
        
        // Check if signing key is available and not revoked
        $signingKey = \LucaLongo\Licensing\Models\LicensingKey::findActiveSigning();
        if (!$signingKey) {
            $this->error('No active signing key available');
            return 3;
        }
        
        if ($signingKey->isRevoked()) {
            $this->error('Signing key is revoked');
            return 3;
        }
        
        // Parse TTL
        $ttlDays = $this->parseTtl($ttl);
        
        try {
            $token = $tokenService->issue($license, $usage, [
                'ttl_days' => $ttlDays
            ]);
            
            $this->info('Offline token issued successfully');
            $this->line('Token:');
            $this->line($token);
            
            return 0;
        } catch (\RuntimeException $e) {
            // Crypto/key errors return code 3
            $this->error('Failed to issue token: ' . $e->getMessage());
            return 3;
        } catch (\Exception $e) {
            // Other errors
            $this->error('Failed to issue token: ' . $e->getMessage());
            return 4;
        }
    }
    
    private function parseTtl(string $ttl): int
    {
        if (preg_match('/^(\d+)d$/', $ttl, $matches)) {
            return (int) $matches[1];
        }
        
        return 7; // Default to 7 days
    }
}