<?php

namespace LucaLongo\Licensing\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\LicensingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'LucaLongo\\Licensing\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Register observers for testing
        \LucaLongo\Licensing\Models\License::observe(\LucaLongo\Licensing\Observers\LicenseObserver::class);
        \LucaLongo\Licensing\Models\LicenseUsage::observe(\LucaLongo\Licensing\Observers\LicenseUsageObserver::class);
        \LucaLongo\Licensing\Models\LicensingKey::observe(\LucaLongo\Licensing\Observers\LicensingKeyObserver::class);
        \LucaLongo\Licensing\Models\LicensingAuditLog::observe(\LucaLongo\Licensing\Observers\LicensingAuditLogObserver::class);
    }

    protected function getPackageProviders($app)
    {
        return [
            LicensingServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('licensing.crypto.keystore.passphrase_env', 'LICENSING_KEY_PASSPHRASE');
        $_ENV['LICENSING_KEY_PASSPHRASE'] = 'test-passphrase-for-testing';
    }

    protected function defineDatabaseMigrations()
    {
        $migrationStubs = [
            'create_licenses_table.php.stub',
            'create_license_usages_table.php.stub',
            'create_license_renewals_table.php.stub',
            'create_license_trials_table.php.stub',
            'create_license_templates_table.php.stub',
            'create_license_transfers_table.php.stub',
            'create_license_transfer_histories_table.php.stub',
            'create_license_transfer_approvals_table.php.stub',
            'create_licensing_keys_table.php.stub',
            'create_licensing_audit_logs_table.php.stub',
        ];

        foreach ($migrationStubs as $stub) {
            $path = __DIR__.'/../database/migrations/'.$stub;
            if (file_exists($path)) {
                $migration = include $path;
                $migration->up();
            }
        }

        // Create users table for testing
        $usersTablePath = __DIR__.'/database/migrations/create_users_table.php';
        if (file_exists($usersTablePath)) {
            $migration = include $usersTablePath;
            $migration->up();
        }
    }
}
