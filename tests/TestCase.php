<?php

namespace LucaLongo\Licensing\Tests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use LucaLongo\Licensing\LicensingServiceProvider;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingAuditLog;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Observers\LicenseObserver;
use LucaLongo\Licensing\Observers\LicenseUsageObserver;
use LucaLongo\Licensing\Observers\LicensingAuditLogObserver;
use LucaLongo\Licensing\Observers\LicensingKeyObserver;
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
        License::observe(LicenseObserver::class);
        LicenseUsage::observe(LicenseUsageObserver::class);
        LicensingKey::observe(LicensingKeyObserver::class);
        LicensingAuditLog::observe(LicensingAuditLogObserver::class);

        // Clear any cached data from previous tests
        LicensingKey::forgetCachedPassphrase();
    }

    protected function tearDown(): void
    {
        // Clear any cached data
        LicensingKey::forgetCachedPassphrase();

        // Clean up key storage
        $keyPath = config('licensing.crypto.keystore.path');
        if ($keyPath && File::exists($keyPath)) {
            // Try to delete directory, but don't fail if it doesn't work (Windows issue)
            try {
                File::deleteDirectory($keyPath);
            } catch (\Exception $e) {
                // On Windows, files might still be locked
                // We'll try to clean individual files at least
                if (PHP_OS_FAMILY === 'Windows') {
                    $files = File::allFiles($keyPath);
                    foreach ($files as $file) {
                        try {
                            File::delete($file);
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

        $driver = env('DB_CONNECTION', 'sqlite');

        if ($driver === 'mysql' || $driver === 'mariadb') {
            config()->set('database.connections.testing', [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'licensing_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => 'InnoDB',
            ]);
        } else {
            config()->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        config()->set('cache.default', 'array');

        // Set app key for encryption
        config()->set('app.key', 'base64:'.base64_encode('32characterslong1234567890123456'));

        config()->set('licensing.crypto.keystore.passphrase', 'test-passphrase-for-testing');

        // Register the package migrations with Laravel's migrator so
        // RefreshDatabase can run them via migrate:fresh and wrap each test
        // in its own transaction. The package ships stubs as .php.stub so
        // vendor:publish can timestamp them at install time; at test time we
        // materialise them once per process into a temp directory with real
        // timestamps.
        $path = static::prepareMigrationFixtures();

        $app->afterResolving('migrator', function ($migrator) use ($path) {
            $migrator->path($path);
        });
    }

    protected static ?string $migrationFixturePath = null;

    protected static function prepareMigrationFixtures(): string
    {
        if (static::$migrationFixturePath !== null && is_dir(static::$migrationFixturePath)) {
            return static::$migrationFixturePath;
        }

        $path = sys_get_temp_dir().'/laravel-licensing-migrations-'.getmypid();
        File::ensureDirectoryExists($path);

        foreach (File::files($path) as $file) {
            File::delete($file->getPathname());
        }

        // Order matters: FK parents before children. SQLite tolerates forward
        // references at DDL time, MySQL does not.
        $stubOrder = [
            'create_license_scopes_table',
            'create_license_templates_table',
            'create_licenses_table',
            'create_license_usages_table',
            'create_license_renewals_table',
            'create_license_trials_table',
            'create_license_transfers_table',
            'create_license_transfer_histories_table',
            'create_license_transfer_approvals_table',
            'create_licensing_keys_table',
            'create_licensing_audit_logs_table',
        ];

        $sourceDir = __DIR__.'/../database/migrations';
        $counter = 0;

        foreach ($stubOrder as $name) {
            $stub = $sourceDir.'/'.$name.'.php.stub';
            if (! file_exists($stub)) {
                continue;
            }
            $counter++;
            $filename = sprintf('2024_01_01_%06d_%s.php', $counter, $name);
            File::copy($stub, $path.'/'.$filename);
        }

        // Test-only users table, also materialised with a timestamp.
        $usersSrc = __DIR__.'/database/migrations/create_users_table.php';
        if (file_exists($usersSrc)) {
            $counter++;
            $filename = sprintf('2024_01_01_%06d_create_users_table.php', $counter);
            File::copy($usersSrc, $path.'/'.$filename);
        }

        static::$migrationFixturePath = $path;

        return $path;
    }
}
