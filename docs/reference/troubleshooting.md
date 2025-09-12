# 🔧 Troubleshooting Guide

Comprehensive troubleshooting guide for common issues and their solutions.

## Table of Contents

- [Installation Issues](#installation-issues)
- [License Activation Problems](#license-activation-problems)
- [Usage Registration Errors](#usage-registration-errors)
- [Offline Verification Issues](#offline-verification-issues)
- [Key Management Problems](#key-management-problems)
- [Performance Issues](#performance-issues)
- [Database Problems](#database-problems)
- [API Errors](#api-errors)
- [Migration Issues](#migration-issues)
- [Debugging Tools](#debugging-tools)

## Installation Issues

### Composer Installation Fails

**Problem**: Package installation fails with dependency errors.

**Solution**:
```bash
# Clear composer cache
composer clear-cache

# Update dependencies
composer update --with-dependencies

# Install with verbose output
composer require masterix21/laravel-licensing -vvv
```

### Migration Fails

**Problem**: Database migration errors during installation.

**Error**:
```
SQLSTATE[42000]: Syntax error or access violation: 1071 Specified key was too long
```

**Solution**:
```php
// In AppServiceProvider::boot()
use Illuminate\Support\Facades\Schema;

Schema::defaultStringLength(191);
```

### Configuration Not Publishing

**Problem**: Config file not appearing after publishing.

**Solution**:
```bash
# Force publish
php artisan vendor:publish --provider="Masterix21\LaravelLicensing\LicensingServiceProvider" --force

# Clear cache
php artisan config:clear
php artisan cache:clear

# Verify file exists
ls -la config/licensing.php
```

## License Activation Problems

### Invalid Activation Key

**Problem**: "Invalid activation key format" error.

**Diagnosis**:
```php
// Check key format
$key = 'XXXX-XXXX-XXXX-XXXX';
$pattern = '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/';

if (!preg_match($pattern, $key)) {
    echo "Invalid format";
}

// Verify in database
$exists = DB::table('licenses')
    ->whereRaw('key_hash = ?', [License::hashKey($key)])
    ->exists();
```

**Solution**:
```php
// Ensure correct format
$key = strtoupper(str_replace(' ', '', $key));
$key = implode('-', str_split($key, 4));

// Activate with error handling
try {
    $license = License::findByKey($key);
    $license->activate();
} catch (LicenseNotFoundException $e) {
    // Key doesn't exist
} catch (LicenseAlreadyActivatedException $e) {
    // Already activated
}
```

### License Already Activated

**Problem**: Cannot activate an already active license.

**Solution**:
```php
// Check current status
if ($license->status === LicenseStatus::Active) {
    // Already active, skip activation
    return $license;
}

// Force reactivation (admin only)
$license->status = LicenseStatus::Pending;
$license->activated_at = null;
$license->save();
$license->activate();
```

### Max Usages Exceeded

**Problem**: "Maximum usage limit reached" error.

**Diagnosis**:
```php
// Check current usage
$activeUsages = $license->usages()
    ->where('status', 'active')
    ->count();

echo "Active: {$activeUsages} / Max: {$license->max_usages}";
```

**Solution**:
```php
// Option 1: Increase limit
$license->max_usages += 5;
$license->save();

// Option 2: Revoke old usage
$oldestUsage = $license->usages()
    ->where('status', 'active')
    ->orderBy('registered_at')
    ->first();

$oldestUsage->revoke();

// Option 3: Auto-replace policy
config(['licensing.policies.over_limit' => 'auto_replace_oldest']);
```

## Usage Registration Errors

### Duplicate Fingerprint

**Problem**: "Usage fingerprint already exists" error.

**Diagnosis**:
```php
// Check existing usage
$existing = LicenseUsage::where('license_id', $license->id)
    ->where('usage_fingerprint', $fingerprint)
    ->first();

if ($existing) {
    echo "Already registered at: " . $existing->registered_at;
}
```

**Solution**:
```php
// Option 1: Reuse existing
if ($existing && $existing->status === 'active') {
    $existing->touch('last_seen_at');
    return $existing;
}

// Option 2: Revoke and re-register
if ($existing) {
    $existing->revoke();
}

$newUsage = $license->registerUsage($fingerprint, [
    'name' => 'Device Name',
    'client_type' => 'desktop',
]);
```

### Fingerprint Generation Issues

**Problem**: Inconsistent fingerprints across app restarts.

**Solution**:
```php
class StableFingerprint implements FingerprintGenerator
{
    public function generate(): string
    {
        // Use stable hardware identifiers
        $components = [
            php_uname('n'), // Machine name
            $this->getMacAddress(),
            $this->getCpuId(),
            config('app.key'), // App-specific salt
        ];
        
        // Remove null values
        $components = array_filter($components);
        
        return hash('sha256', implode('|', $components));
    }
    
    private function getMacAddress(): ?string
    {
        // Platform-specific implementation
        if (PHP_OS_FAMILY === 'Windows') {
            exec('getmac', $output);
            // Parse output
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('ifconfig en0 | grep ether', $output);
            // Parse output
        } else {
            exec('cat /sys/class/net/eth0/address', $output);
            // Parse output
        }
        
        return $output[0] ?? null;
    }
}
```

### Concurrent Registration Race Condition

**Problem**: Multiple registrations succeed despite limit.

**Solution**:
```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($license, $fingerprint) {
    // Lock license row
    $license = License::lockForUpdate()->find($license->id);
    
    // Re-check usage count
    $currentUsages = $license->usages()
        ->where('status', 'active')
        ->count();
    
    if ($currentUsages >= $license->max_usages) {
        throw new UsageLimitException('Limit reached');
    }
    
    // Safe to register
    return $license->registerUsage($fingerprint);
}, 5); // Retry up to 5 times
```

## Offline Verification Issues

### Token Verification Fails

**Problem**: "Invalid token signature" error.

**Diagnosis**:
```bash
# Decode token header
echo "v4.public.eyJ..." | cut -d'.' -f2 | base64 -d | jq

# Check key ID
php artisan licensing:keys:list --format=json | jq '.[] | select(.kid=="signing-key-id")'
```

**Solution**:
```php
// Verify public key is current
$publicKey = file_get_contents(storage_path('app/licensing/keys/public-bundle.json'));
$bundle = json_decode($publicKey, true);

// Check token with correct key
$verifier = new TokenVerifier($bundle['signing']['key']);
$claims = $verifier->verify($token);

// If fails, try with previous key (during rotation)
if (!$claims && isset($bundle['previous'])) {
    $verifier = new TokenVerifier($bundle['previous']['key']);
    $claims = $verifier->verify($token);
}
```

### Clock Skew Errors

**Problem**: "Token not yet valid" or "Token expired" on valid tokens.

**Diagnosis**:
```php
// Check time difference
$tokenTime = $claims['iat'];
$serverTime = time();
$skew = abs($serverTime - $tokenTime);

echo "Clock skew: {$skew} seconds";
```

**Solution**:
```php
// Configure tolerance
config(['licensing.offline_token.clock_skew_seconds' => 300]); // 5 minutes

// Custom verifier with tolerance
class TolerantVerifier extends TokenVerifier
{
    public function verify(string $token): array
    {
        $claims = parent::verify($token);
        
        $now = time();
        $tolerance = 300; // 5 minutes
        
        // Adjust time checks
        if (isset($claims['nbf']) && $claims['nbf'] > $now + $tolerance) {
            throw new TokenNotYetValidException();
        }
        
        if (isset($claims['exp']) && $claims['exp'] < $now - $tolerance) {
            throw new TokenExpiredException();
        }
        
        return $claims;
    }
}
```

### Token Size Too Large

**Problem**: Token exceeds storage or transmission limits.

**Solution**:
```php
// Minimize token payload
$token = $tokenIssuer->issue($license, $fingerprint, [
    'include_entitlements' => false, // Skip large data
    'include_metadata' => false,
    'minimal' => true,
]);

// Compress token
$compressed = gzcompress($token, 9);
$encoded = base64_encode($compressed);

// Decompress on client
$compressed = base64_decode($encoded);
$token = gzuncompress($compressed);
```

## Key Management Problems

### Root Key Missing

**Problem**: "Root key not found" error.

**Solution**:
```bash
# Generate new root key
php artisan licensing:keys:make-root --force

# Restore from backup
cp /backup/root-key.pem storage/app/licensing/keys/root-private.pem
chmod 600 storage/app/licensing/keys/root-private.pem
```

### Key Rotation Fails

**Problem**: Cannot rotate signing keys.

**Diagnosis**:
```bash
# Check current keys
php artisan licensing:keys:list --show-revoked

# Verify root key
php artisan licensing:keys:verify-root
```

**Solution**:
```php
// Manual rotation
$ca = app(CertificateAuthority::class);

// Revoke current
$currentKey = $ca->getActiveSigningKey();
$ca->revokeKey($currentKey->kid, 'manual rotation');

// Issue new
$newKey = $ca->issueSigningKey([
    'kid' => 'signing-' . now()->format('Y-m-d'),
    'valid_days' => 90,
]);

// Activate new key
$ca->activateKey($newKey->kid);
```

### Certificate Chain Invalid

**Problem**: "Certificate chain verification failed".

**Solution**:
```php
// Rebuild chain
$rootKey = KeyStore::getRootKey();
$signingKey = KeyStore::getSigningKey($kid);

$chain = [
    'root' => [
        'public_key' => $rootKey->publicKey(),
        'issued_at' => $rootKey->created_at,
    ],
    'signing' => [
        'public_key' => $signingKey->publicKey(),
        'kid' => $signingKey->kid,
        'signed_by' => 'root',
        'signature' => $rootKey->sign($signingKey->publicKey()),
    ],
];

// Save updated bundle
file_put_contents(
    storage_path('app/licensing/keys/chain.json'),
    json_encode($chain, JSON_PRETTY_PRINT)
);
```

## Performance Issues

### Slow License Queries

**Problem**: License lookups taking too long.

**Diagnosis**:
```sql
EXPLAIN ANALYZE 
SELECT * FROM licenses 
WHERE status = 'active' 
AND expires_at > NOW()
ORDER BY expires_at;
```

**Solution**:
```php
// Add indexes
Schema::table('licenses', function (Blueprint $table) {
    $table->index(['status', 'expires_at']);
    $table->index(['licensable_type', 'licensable_id']);
    $table->index('key_hash');
});

// Use query caching
$licenses = Cache::remember('active-licenses', 300, function () {
    return License::active()
        ->with(['usages', 'template'])
        ->get();
});
```

### High Memory Usage

**Problem**: Out of memory errors during batch operations.

**Solution**:
```php
// Use chunking
License::expired()
    ->chunk(100, function ($licenses) {
        foreach ($licenses as $license) {
            ProcessExpiredLicense::dispatch($license);
        }
        
        // Clear models from memory
        flush();
    });

// Use cursor for large datasets
License::expired()
    ->cursor()
    ->each(function ($license) {
        // Process one at a time
        $license->transitionToExpired();
        
        // Free memory
        unset($license);
    });
```

### Token Generation Bottleneck

**Problem**: Token generation is slow under load.

**Solution**:
```php
// Pre-generate tokens
class TokenPreGenerator
{
    public function generateBatch(int $count): array
    {
        $tokens = [];
        
        for ($i = 0; $i < $count; $i++) {
            $tokens[] = $this->generateBlankToken();
        }
        
        // Store in cache
        Cache::put('token-pool', $tokens, 3600);
        
        return $tokens;
    }
}

// Use token pool
$tokenPool = Cache::get('token-pool', []);
$token = array_pop($tokenPool) ?? $this->generateToken();
Cache::put('token-pool', $tokenPool);
```

## Database Problems

### Connection Pool Exhausted

**Problem**: "Too many connections" database error.

**Solution**:
```php
// config/database.php
'mysql' => [
    'options' => [
        PDO::ATTR_PERSISTENT => false, // Don't persist connections
    ],
    'pool' => [
        'min' => 2,
        'max' => 10,
    ],
],

// Use read replica for queries
$licenses = License::on('mysql-read')
    ->where('status', 'active')
    ->get();
```

### Deadlocks During Registration

**Problem**: "Deadlock found when trying to get lock".

**Solution**:
```php
// Retry logic
use Illuminate\Database\DeadlockException;

$attempts = 0;
$maxAttempts = 3;

while ($attempts < $maxAttempts) {
    try {
        DB::transaction(function () {
            // Registration logic
        });
        break;
    } catch (DeadlockException $e) {
        $attempts++;
        
        if ($attempts >= $maxAttempts) {
            throw $e;
        }
        
        usleep(100000 * $attempts); // Exponential backoff
    }
}
```

### Migration Rollback Issues

**Problem**: Cannot rollback migrations.

**Solution**:
```php
// Add proper down methods
public function down()
{
    // Disable foreign key checks
    Schema::disableForeignKeyConstraints();
    
    // Drop tables in reverse order
    Schema::dropIfExists('license_usages');
    Schema::dropIfExists('license_renewals');
    Schema::dropIfExists('licenses');
    Schema::dropIfExists('license_templates');
    
    // Re-enable foreign key checks
    Schema::enableForeignKeyConstraints();
}
```

## API Errors

### Rate Limiting Issues

**Problem**: "Too many requests" errors.

**Solution**:
```php
// Adjust rate limits
RateLimiter::for('licensing-api', function (Request $request) {
    return [
        Limit::perMinute(100)->by($request->license_key),
        Limit::perMinute(1000)->by($request->ip()),
    ];
});

// Implement backoff
$response = Http::withHeaders([
    'X-License-Key' => $key,
])->retry(3, function ($exception) {
    return $exception->response->status() === 429 
        ? $exception->response->header('Retry-After', 60) * 1000 
        : 100;
})->post('/api/validate');
```

### CORS Errors

**Problem**: Cross-origin requests blocked.

**Solution**:
```php
// config/cors.php
'paths' => ['api/*', 'licensing/*'],
'allowed_origins' => ['https://app.example.com'],
'allowed_methods' => ['GET', 'POST'],
'allowed_headers' => ['Content-Type', 'X-License-Key'],
'exposed_headers' => ['X-RateLimit-Remaining'],
'max_age' => 86400,
```

## Migration Issues

### Upgrading Package Version

**Problem**: Breaking changes after update.

**Solution**:
```bash
# Check migration status
php artisan migrate:status

# Run new migrations
php artisan migrate

# Update configuration
php artisan vendor:publish --tag=licensing-config --force

# Clear all caches
php artisan optimize:clear
```

### Data Migration from Legacy System

**Problem**: Importing existing licenses.

**Solution**:
```php
// Migration script
$legacyLicenses = DB::connection('legacy')
    ->table('old_licenses')
    ->get();

foreach ($legacyLicenses as $old) {
    License::create([
        'uid' => Str::ulid(),
        'key_hash' => License::hashKey($old->license_key),
        'status' => $this->mapStatus($old->status),
        'licensable_type' => User::class,
        'licensable_id' => $this->findUserId($old->email),
        'activated_at' => $old->activated_date,
        'expires_at' => $old->expiry_date,
        'max_usages' => $old->seats ?? 1,
        'meta' => [
            'legacy_id' => $old->id,
            'imported_at' => now(),
        ],
    ]);
}
```

## Debugging Tools

### Enable Debug Mode

```php
// .env
LICENSING_DEBUG=true
LICENSING_LOG_LEVEL=debug

// config/licensing.php
'debug' => env('LICENSING_DEBUG', false),
'log_channel' => 'licensing',
```

### Debug Commands

```bash
# Verify system health
php artisan licensing:health-check --verbose

# Test token generation
php artisan licensing:debug:token --license=XXX --fingerprint=test

# Validate configuration
php artisan licensing:validate-config

# Check database schema
php artisan licensing:check-schema
```

### Logging

```php
// Enable query logging
DB::enableQueryLog();

// Your operation
$license->activate();

// Get queries
$queries = DB::getQueryLog();
Log::debug('License queries', $queries);

// Custom debug logging
Log::channel('licensing')->debug('License activation', [
    'license_id' => $license->id,
    'status' => $license->status,
    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
]);
```

### Testing Tools

```php
// Test license creation
$license = License::factory()
    ->active()
    ->withUsages(3)
    ->create();

// Test token verification
$token = $license->issueOfflineToken('test-device');
$this->assertTrue(TokenVerifier::verify($token));

// Simulate expiration
Carbon::setTestNow(now()->addDays(31));
$this->artisan('licensing:check-expirations')
    ->assertExitCode(0);
```

## Getting Additional Help

### Resources

- [FAQ](faq.md) - Common questions
- [API Documentation](../api/models.md) - Detailed API reference
- [GitHub Issues](https://github.com/masterix21/laravel-licensing/issues) - Report bugs
- [Stack Overflow](https://stackoverflow.com/questions/tagged/laravel-licensing) - Community help

### Support Channels

1. **GitHub Issues**: Bug reports and feature requests
2. **Discussions**: General questions and ideas
3. **Email Support**: For license holders
4. **Slack Community**: Real-time help

### Providing Debug Information

When reporting issues, include:

```php
// System information
php artisan about

// Package version
composer show masterix21/laravel-licensing

// Configuration
php artisan config:show licensing

// Recent errors
tail -n 100 storage/logs/laravel.log | grep -i "licens"

// Database schema
php artisan db:show licenses
```

## Next Steps

- [Security Guide](../advanced/security.md) - Security best practices
- [Performance Tuning](../advanced/performance.md) - Optimization guide
- [FAQ](faq.md) - Frequently asked questions
- [Examples](../examples/practical-examples.md) - Real-world implementations