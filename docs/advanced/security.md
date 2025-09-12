# 🔒 Security Architecture

Comprehensive security guide for Laravel Licensing implementation.

## Security Principles

### Defense in Depth

The package implements multiple layers of security:

1. **Cryptographic Layer** - Ed25519 signatures, PASETO tokens
2. **Application Layer** - Input validation, rate limiting
3. **Data Layer** - Encryption at rest, secure storage
4. **Network Layer** - HTTPS, certificate pinning
5. **Audit Layer** - Comprehensive logging, tamper detection

## Cryptographic Security

### Key Hierarchy

```
Root Key (Offline, Long-lived)
    ├── Signs → Signing Key Certificates
    └── Never exposed to network

Signing Keys (Online, Short-lived)
    ├── Signs → License Tokens
    └── Rotated regularly (30 days)

License Tokens (Client-side)
    ├── Verified with public keys
    └── No secrets stored client-side
```

### Key Generation

```php
use LucaLongo\Licensing\Models\LicensingKey;

class SecureKeyGenerator
{
    /**
     * Generate cryptographically secure root key
     */
    public function generateRootKey(): LicensingKey
    {
        // Use Sodium for Ed25519 key generation
        $keypair = sodium_crypto_sign_keypair();
        
        $privateKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        
        // Encrypt private key with passphrase
        $passphrase = $this->getPassphrase();
        $encryptedPrivate = $this->encryptPrivateKey($privateKey, $passphrase);
        
        // Store with secure permissions
        return LicensingKey::create([
            'kid' => 'root-' . bin2hex(random_bytes(8)),
            'type' => KeyType::Root,
            'algorithm' => 'Ed25519',
            'public_key' => base64_encode($publicKey),
            'private_key' => $encryptedPrivate,
            'status' => KeyStatus::Active,
            'valid_from' => now(),
            'valid_until' => now()->addYears(10),
        ]);
    }
    
    /**
     * Encrypt private key with AES-256-GCM
     */
    private function encryptPrivateKey(string $privateKey, string $passphrase): string
    {
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, 100000, 32, true);
        $nonce = random_bytes(12);
        
        $encrypted = sodium_crypto_aead_aes256gcm_encrypt(
            $privateKey,
            '',  // Additional data
            $nonce,
            $key
        );
        
        return base64_encode($salt . $nonce . $encrypted);
    }
    
    /**
     * Get passphrase from secure source
     */
    private function getPassphrase(): string
    {
        $passphrase = env('LICENSING_KEY_PASSPHRASE');
        
        if (empty($passphrase) || strlen($passphrase) < 32) {
            throw new \Exception('Passphrase must be at least 32 characters');
        }
        
        return $passphrase;
    }
}
```

### Activation Key Security

```php
class ActivationKeySecurity
{
    /**
     * Generate secure activation key
     */
    public function generateSecureKey(): string
    {
        // Use cryptographically secure random
        $bytes = random_bytes(20);
        $key = strtoupper(bin2hex($bytes));
        
        // Add checksum for validation
        $checksum = $this->calculateChecksum($key);
        
        // Format: XXXX-XXXX-XXXX-XXXX-XXXX-CCCC
        return $this->formatKey($key . $checksum);
    }
    
    /**
     * Hash activation key for storage
     */
    public function hashKey(string $key): string
    {
        // Remove formatting
        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($key));
        
        // Use HMAC with app-specific salt
        $salt = config('app.key');
        
        return hash_hmac('sha256', $clean, $salt);
    }
    
    /**
     * Constant-time comparison
     */
    public function verifyKey(string $provided, string $stored): bool
    {
        $hashedProvided = $this->hashKey($provided);
        
        // Use hash_equals for timing attack prevention
        return hash_equals($stored, $hashedProvided);
    }
    
    /**
     * Calculate CRC32 checksum
     */
    private function calculateChecksum(string $key): string
    {
        return strtoupper(substr(hash('crc32', $key), 0, 4));
    }
    
    /**
     * Format key with dashes
     */
    private function formatKey(string $key): string
    {
        return implode('-', str_split($key, 4));
    }
}
```

## Input Validation & Sanitization

### Request Validation

```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LicenseActivationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'activation_key' => [
                'required',
                'string',
                'regex:/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
                new ValidActivationKey(),
            ],
            'device_fingerprint' => [
                'required',
                'string',
                'size:64', // SHA256 hash
                'regex:/^[a-f0-9]{64}$/i',
            ],
            'device_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[\w\s\-\.]+$/', // Alphanumeric, spaces, dashes, dots
            ],
            'metadata' => [
                'nullable',
                'array',
                'max:10', // Max 10 metadata fields
            ],
            'metadata.*' => [
                'string',
                'max:1000', // Max 1KB per field
            ],
        ];
    }
    
    protected function prepareForValidation(): void
    {
        // Sanitize input
        $this->merge([
            'activation_key' => strtoupper(trim($this->activation_key)),
            'device_fingerprint' => strtolower(trim($this->device_fingerprint)),
            'device_name' => strip_tags(trim($this->device_name)),
        ]);
    }
}
```

### SQL Injection Prevention

```php
class SecureQueryBuilder
{
    /**
     * Safe license lookup
     */
    public function findLicenseSecurely(array $criteria): ?License
    {
        $query = License::query();
        
        // Use parameter binding
        if (isset($criteria['key'])) {
            $query->where('key_hash', '=', License::hashKey($criteria['key']));
        }
        
        if (isset($criteria['status'])) {
            // Validate against enum
            if (!in_array($criteria['status'], LicenseStatus::values())) {
                throw new \InvalidArgumentException('Invalid status');
            }
            $query->where('status', '=', $criteria['status']);
        }
        
        if (isset($criteria['licensable_id'])) {
            // Ensure integer
            $query->where('licensable_id', '=', (int) $criteria['licensable_id']);
        }
        
        // Never use raw queries with user input
        return $query->first();
    }
    
    /**
     * Safe JSON query
     */
    public function searchMetadata(string $key, $value): Collection
    {
        // Use JSON path with binding
        return License::whereJsonContains('meta->' . $key, $value)->get();
    }
}
```

## Rate Limiting & DDoS Protection

### API Rate Limiting

```php
namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

class LicenseRateLimiter
{
    public function __construct(
        private RateLimiter $limiter
    ) {}
    
    public function handle(Request $request, \Closure $next, string $type = 'default')
    {
        $key = $this->resolveRequestKey($request, $type);
        $maxAttempts = $this->getMaxAttempts($type);
        
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            // Log potential attack
            $this->logRateLimitExceeded($request, $type);
            
            // Return rate limit response
            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => $this->limiter->availableIn($key),
            ], 429);
        }
        
        $this->limiter->hit($key, $this->getDecayMinutes($type) * 60);
        
        $response = $next($request);
        
        // Add rate limit headers
        return $this->addRateLimitHeaders(
            $response,
            $maxAttempts,
            $this->limiter->remaining($key, $maxAttempts)
        );
    }
    
    private function resolveRequestKey(Request $request, string $type): string
    {
        return match($type) {
            'activation' => 'activation:' . $request->ip(),
            'validation' => 'validation:' . $request->input('license_key'),
            'usage' => 'usage:' . $request->input('fingerprint'),
            default => 'default:' . $request->ip(),
        };
    }
    
    private function getMaxAttempts(string $type): int
    {
        return match($type) {
            'activation' => 5,   // 5 attempts per hour
            'validation' => 60,  // 60 per minute
            'usage' => 30,       // 30 per minute
            default => 100,
        };
    }
    
    private function getDecayMinutes(string $type): int
    {
        return match($type) {
            'activation' => 60,  // 1 hour
            default => 1,        // 1 minute
        };
    }
}
```

### Brute Force Protection

```php
class BruteForceProtection
{
    private int $maxAttempts = 5;
    private int $lockoutMinutes = 15;
    
    /**
     * Track failed activation attempts
     */
    public function recordFailedAttempt(string $key, string $ip): void
    {
        $cacheKey = "failed_activation:{$ip}:{$key}";
        $attempts = Cache::get($cacheKey, 0) + 1;
        
        Cache::put($cacheKey, $attempts, now()->addMinutes($this->lockoutMinutes));
        
        if ($attempts >= $this->maxAttempts) {
            // Block IP temporarily
            $this->blockIP($ip);
            
            // Alert administrators
            event(new SuspiciousActivityDetected($ip, 'Brute force attempt'));
        }
    }
    
    /**
     * Check if IP is blocked
     */
    public function isBlocked(string $ip): bool
    {
        return Cache::has("blocked_ip:{$ip}");
    }
    
    /**
     * Block IP address
     */
    private function blockIP(string $ip): void
    {
        Cache::put(
            "blocked_ip:{$ip}",
            now(),
            now()->addMinutes($this->lockoutMinutes)
        );
        
        // Log to security audit
        Log::channel('security')->warning("IP blocked for brute force", [
            'ip' => $ip,
            'duration' => $this->lockoutMinutes,
        ]);
    }
}
```

## Data Protection

### Encryption at Rest

```php
class DataEncryption
{
    /**
     * Encrypt sensitive license data
     */
    public function encryptLicenseData(array $data): string
    {
        $json = json_encode($data);
        
        // Use Laravel's encryption (AES-256-CBC)
        return Crypt::encryptString($json);
    }
    
    /**
     * Decrypt license data
     */
    public function decryptLicenseData(string $encrypted): array
    {
        try {
            $json = Crypt::decryptString($encrypted);
            return json_decode($json, true);
        } catch (\Exception $e) {
            Log::channel('security')->error('Decryption failed', [
                'error' => $e->getMessage(),
            ]);
            throw new DecryptionException('Failed to decrypt license data');
        }
    }
    
    /**
     * Encrypt database fields
     */
    public function encryptDatabaseField($value): string
    {
        if (empty($value)) {
            return '';
        }
        
        // Use database-specific encryption key
        $key = config('licensing.database_encryption_key');
        
        return encrypt($value, false, $key);
    }
}
```

### Secure Token Storage

```php
class SecureStorage
{
    /**
     * Store token securely on client
     */
    public function storeToken(string $token, string $identifier): void
    {
        // Encrypt token
        $encrypted = $this->encrypt($token);
        
        // Add integrity check
        $hmac = hash_hmac('sha256', $encrypted, $identifier);
        
        $data = [
            'token' => $encrypted,
            'hmac' => $hmac,
            'stored_at' => time(),
        ];
        
        // Platform-specific secure storage
        $this->platformStore($identifier, $data);
    }
    
    /**
     * Retrieve and verify token
     */
    public function retrieveToken(string $identifier): ?string
    {
        $data = $this->platformRetrieve($identifier);
        
        if (!$data) {
            return null;
        }
        
        // Verify integrity
        $expectedHmac = hash_hmac('sha256', $data['token'], $identifier);
        
        if (!hash_equals($data['hmac'], $expectedHmac)) {
            throw new TamperException('Token integrity check failed');
        }
        
        // Check age
        if (time() - $data['stored_at'] > 86400 * 30) {
            throw new ExpiredException('Stored token too old');
        }
        
        return $this->decrypt($data['token']);
    }
}
```

## Network Security

### HTTPS Enforcement

```php
namespace App\Http\Middleware;

class ForceHTTPS
{
    public function handle($request, \Closure $next)
    {
        // Skip in local development
        if (app()->environment('local')) {
            return $next($request);
        }
        
        // Force HTTPS
        if (!$request->secure()) {
            return redirect()->secure($request->getRequestUri());
        }
        
        // Add security headers
        $response = $next($request);
        
        return $response
            ->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-XSS-Protection', '1; mode=block')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
```

### Certificate Pinning

```php
class CertificatePinning
{
    private array $pinnedCertificates = [];
    
    /**
     * Verify server certificate
     */
    public function verifyCertificate(string $url): bool
    {
        $context = stream_context_create([
            "ssl" => [
                "capture_peer_cert" => true,
                "verify_peer" => true,
                "verify_peer_name" => true,
            ],
        ]);
        
        $stream = stream_socket_client(
            "ssl://" . parse_url($url, PHP_URL_HOST) . ":443",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$stream) {
            return false;
        }
        
        $params = stream_context_get_params($stream);
        $cert = $params['options']['ssl']['peer_certificate'];
        
        // Get certificate fingerprint
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
        
        // Check against pinned certificates
        return in_array($fingerprint, $this->pinnedCertificates);
    }
}
```

## Audit & Monitoring

### Security Audit Logging

```php
class SecurityAuditLogger
{
    /**
     * Log security event
     */
    public function logSecurityEvent(string $event, array $context = []): void
    {
        $entry = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'user_id' => auth()->id(),
            'context' => $context,
            'hash' => $this->generateHash($event, $context),
        ];
        
        // Write to security log
        Log::channel('security')->info($event, $entry);
        
        // Store in database for analysis
        DB::table('security_audit')->insert($entry);
        
        // Alert on critical events
        if ($this->isCriticalEvent($event)) {
            $this->alertAdministrators($event, $entry);
        }
    }
    
    /**
     * Generate hash for integrity
     */
    private function generateHash(string $event, array $context): string
    {
        $data = $event . json_encode($context) . now()->timestamp;
        return hash_hmac('sha256', $data, config('app.key'));
    }
    
    /**
     * Check if event is critical
     */
    private function isCriticalEvent(string $event): bool
    {
        return in_array($event, [
            'brute_force_detected',
            'key_compromised',
            'unauthorized_access',
            'data_breach_attempt',
        ]);
    }
}
```

### Intrusion Detection

```php
class IntrusionDetectionSystem
{
    /**
     * Detect suspicious patterns
     */
    public function analyze(Request $request): void
    {
        $patterns = [
            $this->checkSQLInjection($request),
            $this->checkXSS($request),
            $this->checkPathTraversal($request),
            $this->checkCommandInjection($request),
        ];
        
        foreach ($patterns as $threat) {
            if ($threat) {
                $this->handleThreat($threat, $request);
            }
        }
    }
    
    /**
     * Check for SQL injection attempts
     */
    private function checkSQLInjection(Request $request): ?array
    {
        $patterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|ALTER)\b)/i',
            '/(\b(OR|AND)\b\s*\d+\s*=\s*\d+)/i',
            '/(--|\#|\/\*)/i',
        ];
        
        foreach ($request->all() as $key => $value) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return [
                        'type' => 'SQL Injection',
                        'field' => $key,
                        'value' => $value,
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Handle detected threat
     */
    private function handleThreat(array $threat, Request $request): void
    {
        // Log threat
        Log::channel('security')->critical('Threat detected', [
            'threat' => $threat,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
        ]);
        
        // Block IP
        Cache::put("blocked_ip:{$request->ip()}", now(), 3600);
        
        // Alert administrators
        event(new ThreatDetected($threat, $request));
        
        // Terminate request
        abort(403, 'Security violation detected');
    }
}
```

## Compliance & Privacy

### GDPR Compliance

```php
class GDPRCompliance
{
    /**
     * Anonymize personal data
     */
    public function anonymizeData(LicenseUsage $usage): void
    {
        $usage->update([
            'ip' => $this->anonymizeIP($usage->ip),
            'user_agent' => 'REDACTED',
            'name' => 'User ' . substr(hash('sha256', $usage->id), 0, 8),
            'meta' => $this->redactMetadata($usage->meta),
        ]);
    }
    
    /**
     * Export user data
     */
    public function exportUserData(User $user): array
    {
        return [
            'licenses' => $user->licenses->map(function ($license) {
                return [
                    'id' => $license->uid,
                    'status' => $license->status,
                    'created_at' => $license->created_at,
                    'expires_at' => $license->expires_at,
                ];
            }),
            'usages' => $this->getUserUsages($user),
            'audit_logs' => $this->getUserAuditLogs($user),
        ];
    }
    
    /**
     * Delete user data
     */
    public function deleteUserData(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Anonymize licenses
            $user->licenses->each(function ($license) {
                $license->update([
                    'licensable_type' => 'deleted',
                    'licensable_id' => 0,
                ]);
                
                // Anonymize usages
                $license->usages->each(function ($usage) {
                    $this->anonymizeData($usage);
                });
            });
            
            // Delete audit logs
            LicensingAuditLog::where('actor_type', User::class)
                ->where('actor_id', $user->id)
                ->delete();
        });
    }
}
```

## Security Checklist

### Development
- [ ] Use HTTPS in all environments
- [ ] Enable debug mode only in development
- [ ] Use environment variables for secrets
- [ ] Implement proper error handling
- [ ] Validate all user input
- [ ] Use prepared statements for queries
- [ ] Implement CSRF protection
- [ ] Enable XSS protection

### Deployment
- [ ] Rotate all keys and secrets
- [ ] Configure firewall rules
- [ ] Enable rate limiting
- [ ] Set up monitoring and alerting
- [ ] Configure backup encryption
- [ ] Implement intrusion detection
- [ ] Enable audit logging
- [ ] Configure fail2ban or similar

### Operations
- [ ] Regular security audits
- [ ] Penetration testing
- [ ] Key rotation schedule
- [ ] Incident response plan
- [ ] Security training for team
- [ ] Vulnerability scanning
- [ ] Compliance verification
- [ ] Disaster recovery plan

## Next Steps

- [Key Management](key-management.md) - Cryptographic key lifecycle
- [Performance](performance.md) - Security with performance
- [Troubleshooting](../reference/troubleshooting.md) - Security issues
- [API Reference](../api/services.md) - Security services