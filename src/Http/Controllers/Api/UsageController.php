<?php

namespace LucaLongo\Licensing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Licensing;
use LucaLongo\Licensing\Models\License;

class UsageController extends ApiController
{
    public function __construct(
        protected Licensing $licensing,
        protected UsageRegistrar $usageRegistrar
    ) {
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'data' => ['nullable', 'array'],
        ]);

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        $usage = $this->usageRegistrar->findByFingerprint($license, $payload['fingerprint']);
        if (! $usage || ! $usage->isActive()) {
            return $this->error('FINGERPRINT_MISMATCH', 'Fingerprint does not match an active usage for this license', 403);
        }

        $this->licensing->heartbeat($usage);

        if (! empty($payload['data'])) {
            $usage->meta = array_merge((array) ($usage->meta ?? []), $payload['data']);
            $usage->save();
        }

        $usage->refresh();

        return $this->success([
            'usage' => [
                'id' => $usage->getKey(),
                'fingerprint' => $usage->usage_fingerprint,
                'last_seen_at' => $usage->last_seen_at?->toIso8601String(),
                'meta' => $usage->meta,
            ],
        ]);
    }

    protected function validate(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            $response = $this->error('VALIDATION_FAILED', 'Request payload is invalid', 422, [
                'details' => $exception->errors(),
            ]);

            throw new ValidationException($exception->validator, $response);
        }
    }

    protected function findLicense(string $licenseKey): ?License
    {
        return $this->licensing->findByKey($licenseKey);
    }
}
