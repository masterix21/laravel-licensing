<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;

class ListKeysCommand extends Command
{
    protected $signature = 'licensing:keys:list';

    protected $description = 'List all licensing keys with their status';

    public function handle(): int
    {
        $keys = LicensingKey::orderBy('type')->orderBy('created_at', 'desc')->get();

        if ($keys->isEmpty()) {
            $this->warn('No keys found.');

            return 0;
        }

        $headers = ['Type', 'KID', 'Status', 'Valid From', 'Valid Until', 'Revoked At'];
        $rows = [];

        foreach ($keys as $key) {
            $rows[] = [
                $key->type->value,
                $key->kid ?? 'N/A',
                $key->status->value,
                $key->valid_from->format('Y-m-d'),
                $key->valid_until?->format('Y-m-d') ?? 'perpetual',
                $key->revoked_at?->format('Y-m-d') ?? '-',
            ];
        }

        $this->table($headers, $rows);

        return 0;
    }
}
