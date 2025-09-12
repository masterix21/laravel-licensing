<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Database\Eloquent\Collection;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTemplate;

class TemplateService
{
    public function getTemplatesByGroup(string $group): Collection
    {
        return LicenseTemplate::getForGroup($group);
    }

    public function createLicenseFromTemplate(
        string|LicenseTemplate $template,
        array $attributes = []
    ): License {
        return License::createFromTemplate($template, $attributes);
    }

    public function resolveConfiguration(LicenseTemplate $template): array
    {
        return $template->resolveConfiguration();
    }

    public function resolveFeatures(LicenseTemplate $template): array
    {
        return $template->resolveFeatures();
    }

    public function resolveEntitlements(LicenseTemplate $template): array
    {
        return $template->resolveEntitlements();
    }

    public function seedDefaultTemplates(string $group = 'default'): Collection
    {
        $templates = collect();

        $basic = LicenseTemplate::firstOrCreate(
            ['group' => $group, 'name' => 'Basic'],
            [
                'tier_level' => 1,
                'base_configuration' => [
                    'max_usages' => 1,
                    'validity_days' => 365,
                    'grace_days' => 7,
                ],
                'features' => [
                    'basic_features' => true,
                    'api_access' => false,
                    'export_data' => false,
                    'priority_support' => false,
                ],
                'entitlements' => [
                    'max_api_calls_per_day' => 100,
                    'max_storage_gb' => 1,
                    'max_team_members' => 1,
                    'data_retention_days' => 30,
                ],
                'is_active' => true,
            ]
        );
        $templates->push($basic);

        $pro = LicenseTemplate::firstOrCreate(
            ['group' => $group, 'name' => 'Pro'],
            [
                'tier_level' => 2,
                'parent_template_id' => $basic->id,
                'base_configuration' => [
                    'max_usages' => 5,
                    'validity_days' => 365,
                    'grace_days' => 14,
                ],
                'features' => [
                    'api_access' => true,
                    'export_data' => true,
                ],
                'entitlements' => [
                    'max_api_calls_per_day' => 5000,
                    'max_storage_gb' => 10,
                    'max_team_members' => 5,
                    'data_retention_days' => 90,
                ],
                'is_active' => true,
            ]
        );
        $templates->push($pro);

        $enterprise = LicenseTemplate::firstOrCreate(
            ['group' => $group, 'name' => 'Enterprise'],
            [
                'tier_level' => 3,
                'parent_template_id' => $pro->id,
                'base_configuration' => [
                    'max_usages' => -1, // unlimited
                    'validity_days' => 365,
                    'grace_days' => 30,
                ],
                'features' => [
                    'priority_support' => true,
                    'custom_branding' => true,
                    'sso' => true,
                    'audit_logs' => true,
                ],
                'entitlements' => [
                    'max_api_calls_per_day' => -1, // unlimited
                    'max_storage_gb' => 100,
                    'max_team_members' => -1, // unlimited
                    'data_retention_days' => 365,
                ],
                'is_active' => true,
            ]
        );
        $templates->push($enterprise);

        return $templates;
    }

    public function upgradeLicense(License $license, string|LicenseTemplate $newTemplate): License
    {
        if (is_string($newTemplate)) {
            $newTemplate = LicenseTemplate::findBySlug($newTemplate);

            if (! $newTemplate) {
                throw new \InvalidArgumentException("Template not found: {$newTemplate}");
            }
        }

        if ($license->template && $newTemplate->tier_level <= $license->template->tier_level) {
            throw new \InvalidArgumentException('Can only upgrade to a higher tier');
        }

        $config = $newTemplate->resolveConfiguration();

        $license->update([
            'template_id' => $newTemplate->id,
            'max_usages' => $config['max_usages'] ?? $license->max_usages,
            'meta' => array_merge(
                $license->meta ? $license->meta->toArray() : [],
                $config
            ),
        ]);

        return $license->fresh();
    }

    public function getAvailableUpgrades(License $license): Collection
    {
        if (! $license->template) {
            return LicenseTemplate::query()->active()->get();
        }

        return LicenseTemplate::query()
            ->active()
            ->where('group', $license->template->group)
            ->where('tier_level', '>', $license->template->tier_level)
            ->orderedByTier()
            ->get();
    }
}
