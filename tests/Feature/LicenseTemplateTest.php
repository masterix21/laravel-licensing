<?php

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicenseTemplate;
use LucaLongo\Licensing\Services\TemplateService;

beforeEach(function () {
    $this->templateService = app(TemplateService::class);
});

it('creates a license template with auto-generated slug', function () {
    $scope = LicenseScope::create([
        'name' => 'SaaS App',
    ]);

    $template = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Professional Plan',
        'tier_level' => 2,
        'base_configuration' => [
            'max_usages' => 5,
            'validity_days' => 365,
        ],
        'features' => [
            'api_access' => true,
            'export_data' => true,
        ],
        'entitlements' => [
            'max_api_calls' => 5000,
        ],
    ]);

    expect($template->slug)->toBe('scope-'.$scope->id.'-professional-plan');
    expect($template->license_scope_id)->toBe($scope->id);
    expect($template->tier_level)->toBe(2);
});

it('supports template inheritance', function () {
    $scope = LicenseScope::create([
        'name' => 'App',
    ]);

    $basic = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Basic',
        'tier_level' => 1,
        'features' => [
            'feature_a' => true,
            'feature_b' => false,
        ],
        'entitlements' => [
            'max_users' => 5,
        ],
    ]);

    $pro = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Pro',
        'tier_level' => 2,
        'parent_template_id' => $basic->id,
        'features' => [
            'feature_b' => true,
            'feature_c' => true,
        ],
        'entitlements' => [
            'max_users' => 20,
        ],
    ]);

    $resolvedFeatures = $pro->resolveFeatures();
    $resolvedEntitlements = $pro->resolveEntitlements();

    expect($resolvedFeatures)->toMatchArray([
        'feature_a' => true,
        'feature_b' => true,
        'feature_c' => true,
    ]);

    expect($resolvedEntitlements)->toMatchArray([
        'max_users' => 20,
    ]);
});

it('creates a license from template', function () {
    $scope = LicenseScope::create([
        'name' => 'App',
    ]);

    $template = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Pro',
        'tier_level' => 2,
        'base_configuration' => [
            'max_usages' => 10,
            'validity_days' => 30,
            'grace_days' => 7,
        ],
        'features' => [
            'api_access' => true,
        ],
        'entitlements' => [
            'max_api_calls' => 1000,
        ],
    ]);

    $license = License::createFromTemplate($template, [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    expect($license->template_id)->toBe($template->id);
    expect($license->license_scope_id)->toBe($scope->id);
    expect($license->max_usages)->toBe(10);
    expect($license->expires_at->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
    expect($license->meta['grace_days'])->toBe(7);
});

it('creates a license from template slug', function () {
    $scope = LicenseScope::create([
        'name' => 'App',
    ]);

    $template = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Enterprise',
        'tier_level' => 3,
        'base_configuration' => [
            'max_usages' => 100,
        ],
    ]);

    $license = License::createFromTemplate('scope-'.$scope->id.'-enterprise', [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    expect($license->template_id)->toBe($template->id);
    expect($license->license_scope_id)->toBe($scope->id);
    expect($license->max_usages)->toBe(100);
});

it('checks features on license through template', function () {
    $template = LicenseTemplate::create([
        'name' => 'Pro',
        'features' => [
            'api_access' => true,
            'export_data' => true,
            'custom_branding' => false,
        ],
    ]);

    $license = License::createFromTemplate($template, [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    expect($license->hasFeature('api_access'))->toBeTrue();
    expect($license->hasFeature('export_data'))->toBeTrue();
    expect($license->hasFeature('custom_branding'))->toBeFalse();
    expect($license->hasFeature('non_existent'))->toBeFalse();
});

it('gets entitlements from license through template', function () {
    $template = LicenseTemplate::create([
        'name' => 'Pro',
        'entitlements' => [
            'max_api_calls' => 5000,
            'max_storage_gb' => 10,
            'max_team_members' => 5,
        ],
    ]);

    $license = License::createFromTemplate($template, [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    expect($license->getEntitlement('max_api_calls'))->toBe(5000);
    expect($license->getEntitlement('max_storage_gb'))->toBe(10);
    expect($license->getEntitlement('max_team_members'))->toBe(5);
    expect($license->getEntitlement('non_existent'))->toBeNull();
});

it('returns empty features and entitlements for license without template', function () {
    $license = License::create([
        'key_hash' => hash('sha256', 'test-key'),
        'max_usages' => 1,
    ]);

    expect($license->hasFeature('any_feature'))->toBeFalse();
    expect($license->getEntitlement('any_entitlement'))->toBeNull();
    expect($license->getFeatures())->toBe([]);
    expect($license->getEntitlements())->toBe([]);
});

it('upgrades a license to higher tier', function () {
    $scope = LicenseScope::create([
        'name' => 'App',
    ]);

    $basic = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Basic',
        'tier_level' => 1,
        'base_configuration' => ['max_usages' => 1],
    ]);

    $pro = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Pro',
        'tier_level' => 2,
        'base_configuration' => ['max_usages' => 5],
    ]);

    $license = License::createFromTemplate($basic, [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    $templateService = app(TemplateService::class);
    $upgradedLicense = $templateService->upgradeLicense($license, $pro);

    expect($upgradedLicense->template_id)->toBe($pro->id);
    expect($upgradedLicense->max_usages)->toBe(5);
});

it('prevents downgrade to lower tier', function () {
    $scope = LicenseScope::create([
        'name' => 'App',
    ]);

    $pro = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Pro',
        'tier_level' => 2,
    ]);

    $basic = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Basic',
        'tier_level' => 1,
    ]);

    $license = License::createFromTemplate($pro, [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    $templateService = app(TemplateService::class);

    expect(fn () => $templateService->upgradeLicense($license, $basic))
        ->toThrow(\InvalidArgumentException::class, 'Can only upgrade to a higher tier');
});

it('gets available upgrades for a license', function () {
    $scope = LicenseScope::create([
        'name' => 'App',
    ]);

    $basic = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Basic',
        'tier_level' => 1,
    ]);

    $pro = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Pro',
        'tier_level' => 2,
    ]);

    $enterprise = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Enterprise',
        'tier_level' => 3,
    ]);

    $license = License::createFromTemplate($basic, [
        'key_hash' => hash('sha256', 'test-key'),
    ]);

    $templateService = app(TemplateService::class);
    $availableUpgrades = $templateService->getAvailableUpgrades($license);

    expect($availableUpgrades)->toHaveCount(2);
    expect($availableUpgrades->pluck('name')->toArray())->toBe(['Pro', 'Enterprise']);
});

it('retrieves templates by scope', function () {
    $saasScope = LicenseScope::create(['name' => 'SaaS']);
    $mobileScope = LicenseScope::create(['name' => 'Mobile']);

    LicenseTemplate::create(['license_scope_id' => $saasScope->id, 'name' => 'Basic', 'tier_level' => 1]);
    LicenseTemplate::create(['license_scope_id' => $saasScope->id, 'name' => 'Pro', 'tier_level' => 2]);
    LicenseTemplate::create(['license_scope_id' => $mobileScope->id, 'name' => 'Free', 'tier_level' => 1]);

    $saasTemplates = LicenseTemplate::getForScope($saasScope);
    $mobileTemplates = LicenseTemplate::getForScope($mobileScope);

    expect($saasTemplates)->toHaveCount(2);
    expect($saasTemplates->pluck('name')->toArray())->toBe(['Basic', 'Pro']);
    expect($mobileTemplates)->toHaveCount(1);
    expect($mobileTemplates->first()->name)->toBe('Free');
});

it('compares template tiers', function () {
    $scope = LicenseScope::create(['name' => 'App']);

    $basic = LicenseTemplate::create(['license_scope_id' => $scope->id, 'name' => 'Basic', 'tier_level' => 1]);
    $pro = LicenseTemplate::create(['license_scope_id' => $scope->id, 'name' => 'Pro', 'tier_level' => 2]);
    $enterprise = LicenseTemplate::create(['license_scope_id' => $scope->id, 'name' => 'Enterprise', 'tier_level' => 3]);

    expect($pro->isHigherTierThan($basic))->toBeTrue();
    expect($basic->isLowerTierThan($pro))->toBeTrue();
    expect($pro->isSameTierAs($pro))->toBeTrue();
    expect($enterprise->isHigherTierThan($pro))->toBeTrue();
    expect($basic->isHigherTierThan($enterprise))->toBeFalse();
});
