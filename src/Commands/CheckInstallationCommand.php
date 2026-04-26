<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use LucaLongo\Licensing\Models\LicensingKey;

class CheckInstallationCommand extends Command
{
    protected $signature = 'licensing:check';

    protected $description = 'Verify the licensing package installation status';

    public function handle(): int
    {
        $checks = [
            $this->checkConfig(),
            ...$this->checkTables(),
            $this->checkRootKey(),
            $this->checkSigningKey(),
        ];

        $hasFailure = collect($checks)->contains(fn ($row) => $row[1] === 'FAIL');

        $this->table(['Check', 'Status', 'Details'], $checks);

        if ($hasFailure) {
            $this->error('Installation check failed. Resolve the items marked FAIL above.');

            return 1;
        }

        $this->info('Installation OK.');

        return 0;
    }

    protected function checkConfig(): array
    {
        return config('licensing') === null
            ? ['Configuration', 'FAIL', 'Run `php artisan vendor:publish --tag=licensing-config`']
            : ['Configuration', 'OK', 'config/licensing.php loaded'];
    }

    /** @return array<int, array{0:string,1:string,2:string}> */
    protected function checkTables(): array
    {
        $tables = [
            'licenses',
            'license_usages',
            'license_renewals',
            'licensing_keys',
            'licensing_audit_logs',
        ];

        return array_map(fn (string $table) => Schema::hasTable($table)
            ? ["Table {$table}", 'OK', 'present']
            : ["Table {$table}", 'FAIL', 'Run `php artisan migrate`'], $tables);
    }

    protected function checkRootKey(): array
    {
        return LicensingKey::findActiveRoot()
            ? ['Root key', 'OK', 'active root key present']
            : ['Root key', 'FAIL', 'Run `php artisan licensing:keys:make-root`'];
    }

    protected function checkSigningKey(): array
    {
        return LicensingKey::findActiveSigning()
            ? ['Signing key', 'OK', 'active signing key present']
            : ['Signing key', 'FAIL', 'Run `php artisan licensing:keys:issue-signing`'];
    }
}
