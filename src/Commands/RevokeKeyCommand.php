<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;

class RevokeKeyCommand extends Command
{
    protected $signature = 'licensing:keys:revoke {kid : The key ID to revoke} {--reason=manual : Revocation reason} {--at= : When to revoke (ISO datetime)}';

    protected $description = 'Revoke a signing key';

    public function handle(): int
    {
        $kid = $this->argument('kid');
        $reason = $this->option('reason');
        $at = $this->option('at');

        $key = LicensingKey::where('kid', $kid)->first();

        if (! $key) {
            $this->line("Key with KID '{$kid}' not found.");

            return 2; // Not found
        }

        if ($key->isRevoked()) {
            $this->line("Key '{$kid}' is already revoked.");

            return 0;
        }

        if (! $this->confirm("Are you sure you want to revoke key {$kid}?")) {
            $this->line('Revocation cancelled.');

            return 0;
        }

        $revokedAt = $at ? new \DateTimeImmutable($at) : now();
        $key->revoke($reason, $revokedAt);

        $this->line('Key revoked successfully');
        $this->line('Key ID: '.$kid);
        $this->line('Reason: '.$reason);

        return 0;
    }
}
