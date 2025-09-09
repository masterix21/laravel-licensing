<?php

namespace LucaLongo\Licensing;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use LucaLongo\Licensing\Console\Commands\IssueSigningKeyCommand;
use LucaLongo\Licensing\Console\Commands\MakeRootKeyCommand;
use LucaLongo\Licensing\Console\Commands\RotateKeysCommand;
use LucaLongo\Licensing\Contracts\AuditLogger;
use LucaLongo\Licensing\Contracts\CertificateAuthority;
use LucaLongo\Licensing\Contracts\FingerprintResolver;
use LucaLongo\Licensing\Contracts\TokenIssuer;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Services\AuditLoggerService;
use LucaLongo\Licensing\Services\CertificateAuthorityService;
use LucaLongo\Licensing\Services\FingerprintResolverService;
use LucaLongo\Licensing\Services\UsageRegistrarService;

class LicensingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-licensing')
            ->hasConfigFile('licensing')
            ->hasMigrations([
                'create_licenses_table',
                'create_license_usages_table',
                'create_license_renewals_table',
                'create_licensing_keys_table',
                'create_licensing_audit_logs_table',
            ])
            ->hasCommands([
                MakeRootKeyCommand::class,
                IssueSigningKeyCommand::class,
                RotateKeysCommand::class,
            ]);

        if (config('licensing.api.enabled')) {
            $package->hasRoute('api');
        }
    }

    public function packageRegistered(): void
    {
        $this->registerServices();
        $this->registerTokenService();
        $this->registerLicensing();
    }

    protected function registerServices(): void
    {
        $this->app->singleton(CertificateAuthority::class, CertificateAuthorityService::class);
        $this->app->singleton(UsageRegistrar::class, UsageRegistrarService::class);
        $this->app->singleton(FingerprintResolver::class, FingerprintResolverService::class);
        $this->app->singleton(AuditLogger::class, AuditLoggerService::class);
    }

    protected function registerTokenService(): void
    {
        $this->app->singleton(TokenIssuer::class, fn ($app) => 
            $app->make(config('licensing.offline_token.service'))
        );
        
        $this->app->singleton(TokenVerifier::class, fn ($app) => 
            $app->make(config('licensing.offline_token.service'))
        );
        
        $this->app->singleton('licensing.token', fn ($app) => 
            $app->make(config('licensing.offline_token.service'))
        );
    }

    protected function registerLicensing(): void
    {
        $this->app->singleton(\LucaLongo\Licensing\Licensing::class, fn ($app) => 
            new \LucaLongo\Licensing\Licensing(
                $app->make(UsageRegistrar::class),
                $app->make(TokenIssuer::class),
                $app->make(TokenVerifier::class)
            )
        );
    }
}
