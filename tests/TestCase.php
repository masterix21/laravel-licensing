<?php

namespace LucaLongo\Licensing\Tests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('api', function () {
            return Limit::perMinute(600);
        });

        // Register observers for testing
        \LucaLongo\Licensing\Models\License::observe(\LucaLongo\Licensing\Observers\LicenseObserver::class);
        \LucaLongo\Licensing\Models\LicenseUsage::observe(\LucaLongo\Licensing\Observers\LicenseUsageObserver::class);
        \LucaLongo\Licensing\Models\LicensingKey::observe(\LucaLongo\Licensing\Observers\LicensingKeyObserver::class);
        \LucaLongo\Licensing\Models\LicensingAuditLog::observe(\LucaLongo\Licensing\Observers\LicensingAuditLogObserver::class);

        // Clear any cached data from previous tests
        \LucaLongo\Licensing\Models\LicensingKey::forgetCachedPassphrase();
    }

    protected function tearDown(): void
    {
        // Clear any cached data
        \LucaLongo\Licensing\Models\LicensingKey::forgetCachedPassphrase();

        // Clean up key storage
        $keyPath = config('licensing.crypto.keystore.path');
        if ($keyPath && \Illuminate\Support\Facades\File::exists($keyPath)) {
            // Try to delete directory, but don't fail if it doesn't work (Windows issue)
            try {
                \Illuminate\Support\Facades\File::deleteDirectory($keyPath);
            } catch (\Exception $e) {
                // On Windows, files might still be locked
                // We'll try to clean individual files at least
                if (PHP_OS_FAMILY === 'Windows') {
                    $files = \Illuminate\Support\Facades\File::allFiles($keyPath);
                    foreach ($files as $file) {
                        try {
                            \Illuminate\Support\Facades\File::delete($file);
                        } catch (\Exception $e) {
                            // Ignore individual file deletion errors
                        }
                    }
                }
            }
        }

        parent::tearDown();
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
            'foreign_key_constraints' => true,
        ]);

        config()->set('cache.default', 'array');

        // Set app key for encryption
        config()->set('app.key', 'base64:'.base64_encode('32characterslong1234567890123456'));

        config()->set('licensing.crypto.keystore.passphrase_env', 'LICENSING_KEY_PASSPHRASE');
        $_ENV['LICENSING_KEY_PASSPHRASE'] = 'test-passphrase-for-testing';
    }

    protected function defineDatabaseMigrations()
    {
        $migrationStubs = [
            'create_license_scopes_table.php.stub',
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
