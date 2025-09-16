<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Support\Facades\Crypt;
use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRegeneratorContract;
use LucaLongo\Licensing\Models\License;

class EncryptedLicenseKeyRegenerator implements LicenseKeyRegeneratorContract
{
    public function __construct(
        protected LicenseKeyGeneratorContract $generator
    ) {}

    /**
     * Regenerate the license key for a given license.
     *
     * @param License $license
     * @return string The new license key
     */
    public function regenerate(License $license): string
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('License key regeneration is not available');
        }

        // Generate new key
        $newKey = $this->generator->generate($license);

        // Store encrypted version in meta
        $meta = $license->meta ?? [];
        $meta['encrypted_key'] = Crypt::encryptString($newKey);

        // Store previous key hash if exists (for audit)
        if ($license->key_hash) {
            $meta['previous_key_hashes'] = $meta['previous_key_hashes'] ?? [];
            $meta['previous_key_hashes'][] = [
                'hash' => $license->key_hash,
                'replaced_at' => now()->toIso8601String(),
            ];
        }

        // Update license with new hash and meta
        $license->update([
            'key_hash' => License::hashKey($newKey),
            'meta' => $meta,
        ]);

        return $newKey;
    }

    /**
     * Check if the service supports key regeneration.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return config('licensing.key_management.regeneration_enabled', true);
    }
}