<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Models\LicenseUsage;

class CleanupUsagesCommand extends Command
{
    protected $signature = 'licensing:cleanup-usages
                            {--dry-run : Report revocations without applying them}';

    protected $description = 'Revoke license usages inactive beyond the configured threshold';

    public function handle(): int
    {
        $days = config('licensing.policies.usage_inactivity_auto_revoke_days');

        if ($days === null) {
            $this->comment('Auto-revoke disabled (licensing.policies.usage_inactivity_auto_revoke_days is null).');

            return 0;
        }

        $usageClass = config('licensing.models.license_usage', LicenseUsage::class);

        $usages = $usageClass::query()
            ->where('status', UsageStatus::Active)
            ->where('last_seen_at', '<', now()->subDays((int) $days))
            ->get();

        if ($this->option('dry-run')) {
            $this->line("[dry-run] would revoke {$usages->count()} inactive usages (>{$days} days).");

            return 0;
        }

        $usages->each(fn (LicenseUsage $usage) => $usage->revoke('inactivity'));

        $this->info("Revoked {$usages->count()} inactive usages.");

        return 0;
    }
}
