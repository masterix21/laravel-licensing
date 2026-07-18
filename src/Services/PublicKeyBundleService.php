<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use LucaLongo\Licensing\Models\LicensingKey;

class PublicKeyBundleService
{
    public function path(): string
    {
        return config('licensing.publishing.public_bundle_path')
            ?: storage_path('app/licensing/public-bundle.json');
    }

    public function build(LicensingKey $rootKey, Collection $signingKeys): array
    {
        $data = [
            'root' => [
                'kid' => $rootKey->kid,
                'public_key' => $rootKey->getPublicKey(),
                'algorithm' => $rootKey->algorithm,
            ],
            'signing' => [],
        ];

        foreach ($signingKeys as $key) {
            $keyData = [
                'kid' => $key->kid,
                'public_key' => $key->getPublicKey(),
                'algorithm' => $key->algorithm,
                'valid_until' => $key->valid_until?->toIso8601String(),
            ];

            if ($key->certificate) {
                $keyData['certificate'] = $key->certificate;
            }

            $data['signing'][] = $keyData;
        }

        return $data;
    }

    /**
     * Writes the active root and active signing keys to the configured
     * bundle path. Returns null when no active root exists.
     */
    public function export(): ?string
    {
        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            return null;
        }

        $data = $this->build($rootKey, LicensingKey::activeSigning()->get());

        $path = $this->path();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
