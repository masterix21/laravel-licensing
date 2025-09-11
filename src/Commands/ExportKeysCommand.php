<?php

namespace LucaLongo\Licensing\Commands;

use Illuminate\Console\Command;
use LucaLongo\Licensing\Models\LicensingKey;

class ExportKeysCommand extends Command
{
    protected $signature = 'licensing:keys:export 
        {--format=json : Export format (json|jwks|pem)} 
        {--include-chain : Include certificate chain}';

    protected $description = 'Export public keys for distribution';

    public function handle(): int
    {
        $format = $this->option('format');
        $includeChain = $this->option('include-chain');

        $rootKey = LicensingKey::findActiveRoot();
        $signingKeys = LicensingKey::activeSigning()->get();

        if (! $rootKey) {
            $this->error('No active root key found.');

            return 2;
        }

        if ($signingKeys->isEmpty()) {
            $this->warn('No active signing keys found.');
        }

        $export = match ($format) {
            'jwks' => $this->exportAsJwks($rootKey, $signingKeys, $includeChain),
            'pem' => $this->exportAsPem($rootKey, $signingKeys, $includeChain),
            default => $this->exportAsJson($rootKey, $signingKeys, $includeChain),
        };

        $this->line($export);

        return 0;
    }

    private function exportAsJson($rootKey, $signingKeys, $includeChain): string
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

        // Add chain at root level if requested
        if ($includeChain) {
            $data['chain'] = [];
            foreach ($signingKeys as $key) {
                if ($key->certificate) {
                    $data['chain'][] = [
                        'kid' => $key->kid,
                        'certificate' => $key->certificate,
                        'root_public_key' => $rootKey->getPublicKey(),
                    ];
                }
            }
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    private function exportAsJwks($rootKey, $signingKeys, $includeChain): string
    {
        // JWKS-like format adapted for PASETO
        $keys = [];

        // Add root key
        $keys[] = [
            'kid' => $rootKey->kid,
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'use' => 'sig',
            'alg' => 'EdDSA',
            'x' => base64_encode(base64_decode($rootKey->getPublicKey())),
        ];

        // Add signing keys
        foreach ($signingKeys as $key) {
            $keyData = [
                'kid' => $key->kid,
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'use' => 'sig',
                'alg' => 'EdDSA',
                'x' => base64_encode(base64_decode($key->getPublicKey())),
            ];

            if ($includeChain && $key->certificate) {
                $keyData['x5c'] = [$key->certificate];
            }

            $keys[] = $keyData;
        }

        return json_encode(['keys' => $keys], JSON_PRETTY_PRINT);
    }

    private function exportAsPem($rootKey, $signingKeys, $includeChain): string
    {
        // Ed25519 keys are not in PEM format
        $this->warn('PEM format is not applicable for Ed25519 keys. Using JSON format.');

        return $this->exportAsJson($rootKey, $signingKeys, $includeChain);
    }
}
