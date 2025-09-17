<?php

use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicenseTemplate;
use LucaLongo\Licensing\Services\TemplateService;

it('assegna template ad uno scope e restituisce solo quelli attivi', function () {
    $scope = LicenseScope::create([
        'name' => 'Gestione Software',
        'description' => 'Scope per il software principale',
    ]);

    $templateService = app(TemplateService::class);

    $monthly = LicenseTemplate::create([
        'name' => 'Mensile',
        'tier_level' => 1,
        'base_configuration' => [
            'max_usages' => 5,
            'validity_days' => 30,
        ],
    ]);

    $annual = LicenseTemplate::create([
        'name' => 'Annuale',
        'tier_level' => 2,
        'is_active' => false,
    ]);

    $templateService->assignTemplateToScope($scope, $monthly);
    $templateService->assignTemplateToScope($scope, $annual);

    $templates = $templateService->getTemplatesForScope($scope);

    expect($templates)->toHaveCount(1);
    expect($templates->first()->id)->toBe($monthly->id);
    expect($monthly->fresh()->license_scope_id)->toBe($scope->id);
    expect($annual->fresh()->license_scope_id)->toBe($scope->id);
});

it('impedisce di riassegnare un template già collegato ad un altro scope', function () {
    $scopeA = LicenseScope::create(['name' => 'Piattaforma Analytics']);
    $scopeB = LicenseScope::create(['name' => 'Piattaforma E-commerce']);

    $template = LicenseTemplate::create([
        'license_scope_id' => $scopeA->id,
        'name' => 'Trimestrale',
        'tier_level' => 1,
    ]);

    expect($scopeA->assignTemplate($template))->toBeInstanceOf(LicenseTemplate::class);

    expect(fn () => $scopeB->assignTemplate($template))
        ->toThrow(\InvalidArgumentException::class, 'Template already assigned to another scope.');
});

it('crea una licenza partendo da un template assegnato allo scope', function () {
    $scope = LicenseScope::create([
        'name' => 'Applicazione Mobile',
    ]);

    $template = LicenseTemplate::create([
        'license_scope_id' => $scope->id,
        'name' => 'Mensile',
        'tier_level' => 1,
        'base_configuration' => [
            'max_usages' => 2,
            'validity_days' => 30,
            'grace_days' => 5,
        ],
    ]);

    $license = $scope->createLicenseFromTemplate($template->slug, [
        'key_hash' => hash('sha256', 'mobile-license'),
    ]);

    expect($license->license_scope_id)->toBe($scope->id);
    expect($license->template_id)->toBe($template->id);
    expect($license->expires_at->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
});

it('impedisce di usare template non assegnati allo scope', function () {
    $scope = LicenseScope::create([
        'name' => 'Gestione Plugin',
    ]);

    $template = LicenseTemplate::create([
        'name' => 'Premium',
    ]);

    expect(fn () => $scope->createLicenseFromTemplate($template->slug, [
        'key_hash' => hash('sha256', 'plugin-license'),
    ]))->toThrow(\InvalidArgumentException::class);
});
