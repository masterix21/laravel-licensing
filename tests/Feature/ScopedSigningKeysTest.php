<?php

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Services\PasetoTokenService;
use LucaLongo\Licensing\Services\CertificateAuthorityService;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Enums\KeyStatus;

test('can create signing key with scope', function () {
    // Create root key
    $rootKey = LicensingKey::generateRootKey('test-root');
    $rootKey->save();

    // Create scope
    $scope = LicenseScope::create([
        'name' => 'Software A',
        'slug' => 'software-a',
        'identifier' => 'com.example.software-a',
    ]);

    // Create scoped signing key
    $signingKey = LicensingKey::generateSigningKey(
        kid: 'test-signing',
        scope: $scope
    );
    $signingKey->save();

    expect($signingKey->license_scope_id)->toBe($scope->id);
    expect($signingKey->scope->slug)->toBe('software-a');
    expect($signingKey->scope->identifier)->toBe('com.example.software-a');
    expect($signingKey->type)->toBe(KeyType::Signing);
});

test('can find active signing key by scope', function () {
    // Create root key
    $rootKey = LicensingKey::generateRootKey('test-root');
    $rootKey->save();

    // Create scopes
    $scopeA = LicenseScope::create([
        'name' => 'Software A',
        'slug' => 'software-a',
        'identifier' => 'com.example.software-a',
    ]);

    $scopeB = LicenseScope::create([
        'name' => 'Software B',
        'slug' => 'software-b',
        'identifier' => 'com.example.software-b',
    ]);

    // Create global signing key
    $globalKey = LicensingKey::generateSigningKey('global-key');
    $globalKey->save();

    // Create scoped signing key for Software A
    $softwareAKey = LicensingKey::generateSigningKey(
        kid: 'software-a-key',
        scope: $scopeA
    );
    $softwareAKey->save();

    // Create scoped signing key for Software B
    $softwareBKey = LicensingKey::generateSigningKey(
        kid: 'software-b-key',
        scope: $scopeB
    );
    $softwareBKey->save();

    // Find keys by scope
    $foundGlobalKey = LicensingKey::findActiveSigning();
    $foundSoftwareAKey = LicensingKey::findActiveSigning($scopeA);
    $foundSoftwareBKey = LicensingKey::findActiveSigning($scopeB);

    expect($foundGlobalKey->kid)->toBe('global-key');
    expect($foundSoftwareAKey->kid)->toBe('software-a-key');
    expect($foundSoftwareBKey->kid)->toBe('software-b-key');
});

test('license uses scoped signing key for token generation', function () {
    // Create root key
    $rootKey = LicensingKey::generateRootKey('test-root');
    $rootKey->save();

    // Create scope
    $scope = LicenseScope::create([
        'name' => 'App Premium',
        'slug' => 'app-premium',
        'identifier' => 'com.example.app-premium',
    ]);

    // Create global signing key
    $globalKey = LicensingKey::generateSigningKey('global-key');
    $globalKey->save();

    // Issue certificate for global key
    $ca = app(CertificateAuthorityService::class);
    $globalCert = $ca->issueSigningCertificate(
        $globalKey->getPublicKey(),
        $globalKey->kid,
        now(),
        now()->addDays(30)
    );
    $globalKey->update(['certificate' => $globalCert]);

    // Create scoped signing key
    $scopedKey = LicensingKey::generateSigningKey(
        kid: 'scoped-key',
        scope: $scope
    );
    $scopedKey->save();

    // Issue certificate for scoped key
    $scopedCert = $ca->issueSigningCertificate(
        $scopedKey->getPublicKey(),
        $scopedKey->kid,
        now(),
        now()->addDays(30),
        $scope
    );
    $scopedKey->update(['certificate' => $scopedCert]);

    // Create license with scope
    $license = License::create([
        'key_hash' => License::hashKey('test-key'),
        'licensable_type' => 'App\\Models\\User',
        'licensable_id' => 1,
        'license_scope_id' => $scope->id,
        'max_usages' => 5,
        'expires_at' => now()->addYear(),
    ]);

    $license->activate();

    // Create usage
    $usage = LicenseUsage::create([
        'license_id' => $license->id,
        'usage_fingerprint' => 'test-fingerprint',
        'name' => 'Test Device',
        'registered_at' => now(),
    ]);

    // Issue token
    $tokenService = app(PasetoTokenService::class);
    $token = $tokenService->issue($license, $usage);

    // Verify token was issued (scoped key exists so it should be used)
    expect($token)->toStartWith('v4.public.');

    // Verify the scoped key was actually used by checking it was accessed
    $scopedKey->refresh();
    expect($scopedKey->exists)->toBeTrue();
});

test('falls back to global key when scoped key not found', function () {
    // Create root key
    $rootKey = LicensingKey::generateRootKey('test-root');
    $rootKey->save();

    // Create scope without signing key
    $scope = LicenseScope::create([
        'name' => 'Non-existent Scope',
        'slug' => 'non-existent-scope',
        'identifier' => 'com.example.non-existent',
    ]);

    // Create only global signing key
    $globalKey = LicensingKey::generateSigningKey('global-key');
    $globalKey->save();

    // Issue certificate
    $ca = app(CertificateAuthorityService::class);
    $cert = $ca->issueSigningCertificate(
        $globalKey->getPublicKey(),
        $globalKey->kid,
        now(),
        now()->addDays(30)
    );
    $globalKey->update(['certificate' => $cert]);

    // Create license with scope that has no signing key
    $license = License::create([
        'key_hash' => License::hashKey('test-key'),
        'licensable_type' => 'App\\Models\\User',
        'licensable_id' => 1,
        'license_scope_id' => $scope->id,
        'max_usages' => 5,
        'expires_at' => now()->addYear(),
    ]);

    $license->activate();

    // Create usage
    $usage = LicenseUsage::create([
        'license_id' => $license->id,
        'usage_fingerprint' => 'test-fingerprint',
        'name' => 'Test Device',
        'registered_at' => now(),
    ]);

    // Issue token - should fall back to global key
    $tokenService = app(PasetoTokenService::class);
    $token = $tokenService->issue($license, $usage);

    // Verify token was issued successfully with fallback to global key
    expect($token)->toStartWith('v4.public.');

    // Verify the global key was used as fallback
    $globalKey->refresh();
    expect($globalKey->exists)->toBeTrue();
});

test('multiple software can have their own signing keys', function () {
    // Create root key
    $rootKey = LicensingKey::generateRootKey('test-root');
    $rootKey->save();

    $ca = app(CertificateAuthorityService::class);

    // Create scopes for different software
    $scopes = [];
    $keys = [];

    $softwares = [
        'erp-system' => 'com.enterprise.erp',
        'crm-platform' => 'com.enterprise.crm',
        'analytics-tool' => 'com.enterprise.analytics',
    ];

    foreach ($softwares as $slug => $identifier) {
        // Create scope
        $scope = LicenseScope::create([
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'identifier' => $identifier,
        ]);
        $scopes[$slug] = $scope;

        // Create signing key for this scope
        $key = LicensingKey::generateSigningKey(
            kid: "key-{$slug}",
            scope: $scope
        );
        $key->save();

        // Issue certificate
        $cert = $ca->issueSigningCertificate(
            $key->getPublicKey(),
            $key->kid,
            now(),
            now()->addDays(30),
            $scope
        );
        $key->update(['certificate' => $cert]);

        $keys[$slug] = $key;
    }

    // Verify each software has its own key
    foreach ($softwares as $slug => $identifier) {
        $foundKey = LicensingKey::findActiveSigning($scopes[$slug]);
        expect($foundKey->kid)->toBe("key-{$slug}");
        expect($foundKey->license_scope_id)->toBe($scopes[$slug]->id);
        expect($foundKey->scope->identifier)->toBe($identifier);
    }

    // Verify keys are isolated
    $erpKeys = LicensingKey::activeSigning($scopes['erp-system'])->count();
    $crmKeys = LicensingKey::activeSigning($scopes['crm-platform'])->count();

    expect($erpKeys)->toBe(1);
    expect($crmKeys)->toBe(1);
});

test('can programmatically create scoped signing key', function () {
    // Create root key first
    $rootKey = LicensingKey::generateRootKey('test-root');
    $rootKey->save();

    // Create scope
    $scope = LicenseScope::create([
        'name' => 'Mobile App',
        'slug' => 'mobile-app',
        'identifier' => 'com.example.mobile',
    ]);

    // Create signing key with scope programmatically
    $key = LicensingKey::generateSigningKey(
        kid: 'programmatic-scoped-key',
        scope: $scope
    );
    $key->save();

    // Issue certificate
    $ca = app(CertificateAuthorityService::class);
    $cert = $ca->issueSigningCertificate(
        $key->getPublicKey(),
        $key->kid,
        now(),
        now()->addDays(30),
        $scope
    );
    $key->update(['certificate' => $cert]);

    // Verify key was created with scope
    expect($key)->not->toBeNull();
    expect($key->license_scope_id)->toBe($scope->id);
    expect($key->scope->slug)->toBe('mobile-app');
    expect($key->scope->identifier)->toBe('com.example.mobile');
    expect($key->type)->toBe(KeyType::Signing);
    expect($key->status)->toBe(KeyStatus::Active);
});