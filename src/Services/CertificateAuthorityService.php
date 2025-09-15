<?php

namespace LucaLongo\Licensing\Services;

use LucaLongo\Licensing\Contracts\CertificateAuthority;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicensingKey;

class CertificateAuthorityService implements CertificateAuthority
{
    public function issueSigningCertificate(
        string $signingPublicKey,
        string $kid,
        \DateTimeInterface $validFrom,
        \DateTimeInterface $validUntil,
        ?LicenseScope $scope = null
    ): string {
        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            throw new \RuntimeException('No active root key found');
        }

        $certificate = [
            'kid' => $kid,
            'public_key' => $signingPublicKey,
            'valid_from' => $validFrom->format('c'),
            'valid_until' => $validUntil->format('c'),
            'issued_at' => now()->format('c'),
            'issuer_kid' => $rootKey->kid,
        ];

        if ($scope) {
            $certificate['scope'] = $scope->slug;
            $certificate['scope_identifier'] = $scope->identifier;
        }

        $certificateJson = json_encode($certificate);
        $privateKeyBase64 = $rootKey->getPrivateKey();

        if (! $privateKeyBase64) {
            throw new \RuntimeException('Root private key not available');
        }

        // Sign with Ed25519
        $privateKey = base64_decode($privateKeyBase64);
        $signature = sodium_crypto_sign_detached($certificateJson, $privateKey);

        $signedCertificate = [
            'certificate' => $certificate,
            'signature' => base64_encode($signature),
        ];

        return json_encode($signedCertificate);
    }

    public function verifyCertificate(string $certificate): bool
    {
        try {
            $data = json_decode($certificate, true);

            if (! isset($data['certificate'], $data['signature'])) {
                return false;
            }

            $rootKey = LicensingKey::findByKid($data['certificate']['issuer_kid'] ?? '');

            if (! $rootKey || ! $rootKey->isActive()) {
                return false;
            }

            $certificateJson = json_encode($data['certificate']);
            $signature = base64_decode($data['signature']);
            $publicKey = base64_decode($rootKey->getPublicKey());

            return sodium_crypto_sign_verify_detached($signature, $certificateJson, $publicKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCertificateChain(string $kid): array
    {
        $signingKey = LicensingKey::findByKid($kid);

        if (! $signingKey) {
            throw new \RuntimeException("Signing key not found: {$kid}");
        }

        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            throw new \RuntimeException('No active root key found');
        }

        return [
            'signing' => [
                'kid' => $signingKey->kid,
                'public_key' => $signingKey->getPublicKey(),
                'certificate' => $signingKey->getCertificate(),
                'valid_from' => $signingKey->valid_from->format('c'),
                'valid_until' => $signingKey->valid_until?->format('c'),
            ],
            'root' => [
                'kid' => $rootKey->kid,
                'public_key' => $rootKey->getPublicKey(),
                'valid_from' => $rootKey->valid_from->format('c'),
                'valid_until' => $rootKey->valid_until?->format('c'),
            ],
        ];
    }

    public function getRootPublicKey(): string
    {
        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            throw new \RuntimeException('No active root key found');
        }

        return $rootKey->getPublicKey();
    }
}
