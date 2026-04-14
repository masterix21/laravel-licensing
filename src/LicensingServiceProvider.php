<?php

namespace LucaLongo\Licensing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\RateLimiter;
use LucaLongo\Licensing\Commands\ExportKeysCommand;
use LucaLongo\Licensing\Commands\IssueOfflineTokenCommand;
use LucaLongo\Licensing\Commands\IssueSigningKeyCommand;
use LucaLongo\Licensing\Commands\ListKeysCommand;
use LucaLongo\Licensing\Commands\MakeRootKeyCommand;
use LucaLongo\Licensing\Commands\RevokeKeyCommand;
use LucaLongo\Licensing\Commands\RotateKeysCommand;
use LucaLongo\Licensing\Contracts\AuditLogger;
use LucaLongo\Licensing\Contracts\CertificateAuthority;
use LucaLongo\Licensing\Contracts\FingerprintResolver;
use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRegeneratorContract;
use LucaLongo\Licensing\Contracts\LicenseKeyRetrieverContract;
use LucaLongo\Licensing\Contracts\TokenIssuer;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Observers\LicenseObserver;
use LucaLongo\Licensing\Observers\LicenseUsageObserver;
use LucaLongo\Licensing\Observers\LicensingAuditLogObserver;
use LucaLongo\Licensing\Observers\LicensingKeyObserver;
use LucaLongo\Licensing\Services\AuditLoggerService;
use LucaLongo\Licensing\Services\CertificateAuthorityService;
use LucaLongo\Licensing\Services\FingerprintResolverService;
use LucaLongo\Licensing\Services\TemplateService;
use LucaLongo\Licensing\Services\UsageRegistrarService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

use function class_exists;

class LicensingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-licensing')
            ->hasConfigFile('licensing')
            ->hasMigrations([
                // Order matters: parents before children, FK targets before FK holders.
                'create_license_scopes_table',
                'create_license_templates_table',
                'create_licenses_table',
                'create_license_usages_table',
                'create_license_renewals_table',
                'create_license_trials_table',
                'add_trial_and_duration_columns_to_license_templates_table',
                'create_license_transfers_table',
                'create_license_transfer_histories_table',
                'create_license_transfer_approvals_table',
                'create_licensing_keys_table',
                'create_licensing_audit_logs_table',
            ])
            ->hasCommands([
                MakeRootKeyCommand::class,
                IssueSigningKeyCommand::class,
                RotateKeysCommand::class,
                ListKeysCommand::class,
                RevokeKeyCommand::class,
                ExportKeysCommand::class,
                IssueOfflineTokenCommand::class,
            ]);

        if (config('licensing.api.enabled')) {
            $package->hasRoute('api');
        }
    }

    public function packageRegistered(): void
    {
        $this->registerServices();
        $this->registerLicenseKeyServices();
        $this->registerTokenService();
        $this->registerLicensing();
        $this->registerObservers();
        $this->registerPassphraseCleanup();
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiters();
    }

    protected function registerServices(): void
    {
        $this->app->singleton(CertificateAuthority::class, CertificateAuthorityService::class);
        $this->app->singleton(UsageRegistrar::class, UsageRegistrarService::class);
        $this->app->singleton(FingerprintResolver::class, FingerprintResolverService::class);
        $this->app->singleton(AuditLogger::class, AuditLoggerService::class);
        $this->app->singleton(TemplateService::class);
    }

    protected function registerLicenseKeyServices(): void
    {
        // Register key generator
        $this->app->singleton(LicenseKeyGeneratorContract::class, function ($app) {
            $class = config('licensing.services.key_generator');

            return new $class;
        });

        // Register key retriever
        $this->app->singleton(LicenseKeyRetrieverContract::class, function ($app) {
            $class = config('licensing.services.key_retriever');

            return new $class;
        });

        // Register key regenerator
        $this->app->singleton(LicenseKeyRegeneratorContract::class, function ($app) {
            $class = config('licensing.services.key_regenerator');
            $generator = $app->make(LicenseKeyGeneratorContract::class);

            return new $class($generator);
        });
    }

    protected function registerTokenService(): void
    {
        $this->app->singleton(TokenIssuer::class, fn ($app) => $app->make(config('licensing.offline_token.service'))
        );

        $this->app->singleton(TokenVerifier::class, fn ($app) => $app->make(config('licensing.offline_token.service'))
        );

        $this->app->singleton('licensing.token', fn ($app) => $app->make(config('licensing.offline_token.service'))
        );
    }

    protected function registerLicensing(): void
    {
        $this->app->singleton(Licensing::class, fn ($app) => new Licensing(
            $app->make(UsageRegistrar::class),
            $app->make(TokenIssuer::class),
            $app->make(TokenVerifier::class)
        )
        );
    }

    protected function registerPassphraseCleanup(): void
    {
        $cleanup = static function () {
            LicensingKey::forgetCachedPassphrase();
        };

        // Octane: clear passphrase after each request
        if (class_exists('Laravel\Octane\Events\RequestTerminated')) {
            $this->app['events']->listen('Laravel\Octane\Events\RequestTerminated', $cleanup);
            $this->app['events']->listen('Laravel\Octane\Events\TaskTerminated', $cleanup);
        }

        // Queue: clear passphrase when worker stops
        $this->app['events']->listen(WorkerStopping::class, $cleanup);
        $this->app['events']->listen(JobProcessed::class, $cleanup);
        $this->app['events']->listen(JobFailed::class, $cleanup);
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('licensing-validate', function ($request) {
            return Limit::perMinute(config('licensing.rate_limit.validate_per_minute', 60))
                ->by($request->ip());
        });

        RateLimiter::for('licensing-register', function ($request) {
            return Limit::perMinute(config('licensing.rate_limit.register_per_minute', 30))
                ->by($request->ip());
        });

        RateLimiter::for('licensing-token', function ($request) {
            return Limit::perMinute(config('licensing.rate_limit.token_per_minute', 20))
                ->by($request->ip());
        });
    }

    protected function registerObservers(): void
    {
        License::observe(LicenseObserver::class);
        LicenseUsage::observe(LicenseUsageObserver::class);
        LicensingKey::observe(LicensingKeyObserver::class);
        LicensingAuditLog::observe(LicensingAuditLogObserver::class);
    }
}
