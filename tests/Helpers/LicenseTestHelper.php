<?php

namespace LucaLongo\Licensing\Tests\Helpers;

use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;

trait LicenseTestHelper
{
    protected function createLicense(array $attributes = []): License
    {
        return License::create(array_merge([
            'key_hash' => License::hashKey('TEST-LICENSE-KEY-'.uniqid()),
            'status' => LicenseStatus::Active,
            'licensable_type' => 'App\Models\User',
            'licensable_id' => 1,
            'activated_at' => now(),
            'expires_at' => now()->addYear(),
            'max_usages' => 5,
            'meta' => [],
        ], $attributes));
    }

    protected function createUsage(License $license, array $attributes = []): LicenseUsage
    {
        return $license->usages()->create(array_merge([
            'usage_fingerprint' => hash('sha256', 'test-device-'.rand()),
            'status' => UsageStatus::Active->value,
            'registered_at' => now(),
            'last_seen_at' => now(),
            'client_type' => 'test',
            'name' => 'Test Device',
        ], $attributes));
    }

    protected function createRootKey(): LicensingKey
    {
        $key = new LicensingKey;
        $key->generate(['type' => KeyType::Root]);

        return $key;
    }

    protected function createSigningKey(): LicensingKey
    {
        // Only create root key if one doesn't exist
        if (! LicensingKey::findActiveRoot()) {
            $this->createRootKey();
        }

        $key = new LicensingKey;
        $key->generate(['type' => KeyType::Signing]);

        $ca = app(\LucaLongo\Licensing\Services\CertificateAuthorityService::class);
        $certificate = $ca->issueSigningCertificate(
            $key->getPublicKey(),
            $key->kid,
            $key->valid_from,
            $key->valid_until
        );

        $key->update(['certificate' => $certificate]);

        return $key;
    }

    protected function generateFingerprint(string $seed = 'test'): string
    {
        return hash('sha256', $seed.'-'.time());
    }

    protected function createActiveLicense(array $attributes = []): License
    {
        $key = $attributes['key'] ?? 'TEST-LICENSE-KEY-'.uniqid();
        $license = $this->createLicense(array_merge([
            'status' => LicenseStatus::Active,
            'key_hash' => License::hashKey($key),
        ], $attributes));

        // Store the original key as a property for testing
        $license->key = $key;

        return $license;
    }
}
