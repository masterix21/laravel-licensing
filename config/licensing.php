<?php

return [
    'models' => [
        'license' => \LucaLongo\Licensing\Models\License::class,
        'license_usage' => \LucaLongo\Licensing\Models\LicenseUsage::class,
        'license_renewal' => \LucaLongo\Licensing\Models\LicenseRenewal::class,
        'license_template' => \LucaLongo\Licensing\Models\LicenseTemplate::class,
        'licensing_key' => \LucaLongo\Licensing\Models\LicensingKey::class,
        'audit_log' => \LucaLongo\Licensing\Models\LicensingAuditLog::class,
    ],

    'policies' => [
        'over_limit' => 'reject', // reject | auto_replace_oldest
        'grace_days' => 14,
        'usage_inactivity_auto_revoke_days' => null, // null to disable
        'unique_usage_scope' => 'license', // license | global
    ],

    'templates' => [
        'enabled' => true,
        'allow_inheritance' => true,
        'default_group' => 'default',
    ],

    'trials' => [
        'enabled' => true,
        'default_duration_days' => 14,
        'allow_extensions' => true,
        'max_extension_days' => 7,
        'prevent_reset_attempts' => true,
        'default_limitations' => [
            // 'max_api_calls' => 1000,
            // 'max_records' => 100,
        ],
        'default_feature_restrictions' => [
            // 'export',
            // 'api_access',
        ],
    ],

    'offline_token' => [
        'enabled' => true,
        'service' => \LucaLongo\Licensing\Services\PasetoTokenService::class,
        'issuer' => 'laravel-licensing',
        'ttl_days' => 7,
        'force_online_after_days' => 14,
        'clock_skew_seconds' => 60,
    ],

    'crypto' => [
        'algorithm' => 'ed25519', // ed25519 | ES256
        'keystore' => [
            'driver' => 'files', // files | database | custom
            'path' => storage_path('app/licensing/keys'),
            'passphrase_env' => 'LICENSING_KEY_PASSPHRASE',
        ],
    ],

    'publishing' => [
        'jwks_url' => null,
        'public_bundle_path' => storage_path('app/licensing/public-bundle.json'),
    ],

    'rate_limit' => [
        'validate_per_minute' => 60,
        'token_per_minute' => 20,
        'register_per_minute' => 30,
    ],

    'audit' => [
        'enabled' => true,
        'store' => 'database', // database | file
        'retention_days' => 90,
        'hash_chain' => true, // Enable hash chaining for tamper-evidence
    ],

    'api' => [
        'enabled' => true,
        'prefix' => 'api/licensing/v1',
        'middleware' => ['api', 'throttle:api'],
    ],

    'scheduler' => [
        'check_expirations' => [
            'enabled' => true,
            'time' => '02:00',
        ],
        'cleanup_inactive_usages' => [
            'enabled' => false,
            'time' => '03:00',
        ],
        'notify_expiring' => [
            'enabled' => true,
            'time' => '09:00',
            'days_before' => [30, 14, 7, 3, 1],
        ],
    ],
];
