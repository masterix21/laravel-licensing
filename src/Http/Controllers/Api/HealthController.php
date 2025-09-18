<?php

namespace LucaLongo\Licensing\Http\Controllers\Api;

use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Models\LicensingKey;

class HealthController extends ApiController
{
    public function show()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'root_key' => $this->checkRootKey(),
            'signing_key' => $this->checkSigningKey(),
        ];

        $isHealthy = collect($checks)->every(fn ($result) => $result['status'] === 'ok');

        return $this->success([
            'status' => $isHealthy ? 'healthy' : 'degraded',
            'checks' => $checks,
        ]);
    }

    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ];
        }
    }

    protected function checkRootKey(): array
    {
        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            return [
                'status' => 'error',
                'message' => 'No active root key found',
            ];
        }

        return [
            'status' => 'ok',
            'kid' => $rootKey->kid,
            'valid_until' => $rootKey->valid_until?->toIso8601String(),
        ];
    }

    protected function checkSigningKey(): array
    {
        $signingKey = LicensingKey::findActiveSigning();

        if (! $signingKey) {
            return [
                'status' => 'error',
                'message' => 'No active signing key found',
            ];
        }

        return [
            'status' => 'ok',
            'kid' => $signingKey->kid,
            'valid_until' => $signingKey->valid_until?->toIso8601String(),
        ];
    }
}
