# 🔐 Offline Verification

Complete guide to implementing offline license verification using cryptographic tokens.

## Overview

Offline verification allows clients to validate licenses without contacting the server, using cryptographically signed tokens with a two-level key hierarchy for secure rotation.

### Key Features

- **PASETO v4 tokens** (default) or JWS/JWT
- **Ed25519 signatures** for security
- **Two-level key hierarchy** (Root → Signing)
- **Certificate chains** for trust validation
- **Clock skew tolerance**
- **Force online windows**

## Architecture

### Two-Level Key Hierarchy

```
┌──────────────┐
│   Root Key   │ (Long-lived, offline)
│  (Ed25519)   │ Signs signing certificates
└──────┬───────┘
       │
       │ Issues
       ▼
┌──────────────┐
│ Signing Key  │ (Short-lived, rotatable)
│  (Ed25519)   │ Signs tokens
└──────┬───────┘
       │
       │ Signs
       ▼
┌──────────────┐
│    Token     │ (Client verification)
│   (PASETO)   │ Contains license claims
└──────────────┘
```

## Token Generation

### Issuing Offline Tokens

```php
use LucaLongo\Licensing\Services\PasetoTokenService;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

class OfflineTokenController
{
    public function __construct(
        private PasetoTokenService $tokenService
    ) {}
    
    public function issueToken(License $license, LicenseUsage $usage)
    {
        // Issue token with custom options
        $token = $this->tokenService->issue($license, $usage, [
            'ttl_days' => 7,                    // Token valid for 7 days
            'force_online_after' => 14,          // Force online check after 14 days
            'include_entitlements' => true,      // Include all entitlements
            'include_features' => true,          // Include feature flags
            'custom_claims' => [                 // Add custom claims
                'organization' => $license->licensable->name,
                'support_level' => $license->meta['support_level'],
            ],
        ]);
        
        return [
            'token' => $token,
            'expires_at' => now()->addDays(7),
            'refresh_after' => now()->addDays(5),
        ];
    }
}
```

### Token Structure

```php
// PASETO v4 Token Payload
{
    // Standard claims
    "iat": "2024-01-01T00:00:00Z",        // Issued at
    "nbf": "2024-01-01T00:00:00Z",        // Not before
    "exp": "2024-01-08T00:00:00Z",        // Expiration
    
    // License claims
    "license_id": "01HK3N4E5X6Y7Z8A9BCDEFGHJ",
    "license_key_hash": "sha256:abc123...",
    "status": "active",
    
    // Usage claims
    "usage_fingerprint": "sha256:device123...",
    "usage_id": "01HK3N4E5X6Y7Z8A9BCDEFGHK",
    
    // Limits
    "max_usages": 5,
    "grace_until": "2024-01-15T00:00:00Z",
    
    // Features & Entitlements
    "features": {
        "api_access": true,
        "advanced_analytics": true,
        "white_label": false
    },
    "entitlements": {
        "api_calls_per_day": 10000,
        "storage_gb": 100
    },
    
    // Metadata
    "force_online_after": "2024-01-14T00:00:00Z",
    "kid": "signing-key-2024-01",          // Key ID
    "version": "1.0"
}
```

## Key Management

### Generating Keys

```php
use LucaLongo\Licensing\Models\LicensingKey;

// Generate root key (one-time setup)
$rootKey = LicensingKey::generateRootKey('root-2024');

// Generate signing key (monthly rotation)
$signingKey = LicensingKey::generateSigningKey('signing-2024-01');

// The signing key includes a certificate signed by root
```

### Key Rotation Strategy

```php
class KeyRotationService
{
    /**
     * Rotate signing keys
     */
    public function rotate(string $reason = 'routine'): void
    {
        DB::transaction(function () use ($reason) {
            // Get current signing key
            $currentKey = LicensingKey::findActiveSigning();
            
            if ($currentKey) {
                // Revoke current key
                $currentKey->revoke($reason, now());
            }
            
            // Generate new signing key
            $newKey = LicensingKey::generateSigningKey(
                'signing-' . now()->format('Y-m')
            );
            
            // Update published bundle
            $this->updatePublicBundle($newKey);
            
            // Notify clients if compromised
            if ($reason === 'compromised') {
                event(new KeyCompromised($currentKey));
            }
        });
    }
    
    /**
     * Update public key bundle
     */
    private function updatePublicBundle(LicensingKey $signingKey): void
    {
        $rootKey = LicensingKey::findActiveRoot();
        
        $bundle = [
            'version' => '2.0',
            'issued_at' => now()->toIso8601String(),
            'root' => [
                'kid' => $rootKey->kid,
                'public_key' => $rootKey->public_key,
                'algorithm' => 'Ed25519',
            ],
            'signing_keys' => [
                [
                    'kid' => $signingKey->kid,
                    'public_key' => $signingKey->public_key,
                    'certificate' => $signingKey->certificate,
                    'valid_from' => $signingKey->valid_from,
                    'valid_until' => $signingKey->valid_until,
                    'revoked_at' => $signingKey->revoked_at,
                ],
            ],
        ];
        
        Storage::put(
            'licensing/public-bundle.json',
            json_encode($bundle, JSON_PRETTY_PRINT)
        );
    }
}
```

## Client-Side Verification

### PHP Client Implementation

```php
namespace App\Client;

use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;

class OfflineVerifier
{
    private array $publicKeys;
    private int $clockSkew = 60; // seconds
    
    public function __construct(array $publicKeyBundle)
    {
        $this->publicKeys = $this->parseKeyBundle($publicKeyBundle);
    }
    
    /**
     * Verify token offline
     */
    public function verify(string $token): array
    {
        try {
            // Parse token header to get kid
            $kid = $this->extractKid($token);
            
            // Get signing public key
            $signingKey = $this->publicKeys['signing'][$kid] ?? null;
            
            if (!$signingKey) {
                throw new \Exception("Unknown key ID: {$kid}");
            }
            
            // Verify certificate chain
            if (!$this->verifyCertificateChain($signingKey)) {
                throw new \Exception("Invalid certificate chain");
            }
            
            // Check key revocation
            if ($this->isKeyRevoked($signingKey)) {
                throw new \Exception("Key has been revoked");
            }
            
            // Verify token signature
            $parser = new Parser();
            $publicKey = new AsymmetricPublicKey($signingKey['public_key']);
            
            $claims = $parser->parse($token, $publicKey);
            
            // Validate claims
            $this->validateClaims($claims);
            
            return $claims;
            
        } catch (\Exception $e) {
            throw new VerificationException(
                "Token verification failed: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Verify certificate chain
     */
    private function verifyCertificateChain(array $signingKey): bool
    {
        $certificate = json_decode($signingKey['certificate'], true);
        
        // Verify signature with root key
        $rootKey = $this->publicKeys['root'];
        
        return sodium_crypto_sign_verify_detached(
            base64_decode($certificate['signature']),
            $certificate['payload'],
            base64_decode($rootKey['public_key'])
        );
    }
    
    /**
     * Validate token claims
     */
    private function validateClaims(array $claims): void
    {
        $now = time();
        
        // Check expiration with clock skew
        if (isset($claims['exp'])) {
            $exp = strtotime($claims['exp']);
            if ($now > ($exp + $this->clockSkew)) {
                throw new \Exception("Token has expired");
            }
        }
        
        // Check not before with clock skew
        if (isset($claims['nbf'])) {
            $nbf = strtotime($claims['nbf']);
            if ($now < ($nbf - $this->clockSkew)) {
                throw new \Exception("Token not yet valid");
            }
        }
        
        // Check force online window
        if (isset($claims['force_online_after'])) {
            $forceOnline = strtotime($claims['force_online_after']);
            if ($now > $forceOnline) {
                throw new \Exception("Online validation required");
            }
        }
        
        // Verify license status
        if ($claims['status'] !== 'active' && $claims['status'] !== 'grace') {
            throw new \Exception("License is not active");
        }
        
        // Verify device fingerprint
        if (!$this->verifyFingerprint($claims['usage_fingerprint'])) {
            throw new \Exception("Device fingerprint mismatch");
        }
    }
    
    /**
     * Verify device fingerprint
     */
    private function verifyFingerprint(string $expectedFingerprint): bool
    {
        $currentFingerprint = $this->generateFingerprint();
        
        return hash_equals($expectedFingerprint, $currentFingerprint);
    }
}
```

### JavaScript Client Implementation

```javascript
// client.js
import { V4 } from '@paseto/paseto-js';

class OfflineVerifier {
    constructor(publicKeyBundle) {
        this.publicKeys = this.parseBundle(publicKeyBundle);
        this.clockSkew = 60; // seconds
    }
    
    async verify(token) {
        try {
            // Extract kid from token
            const kid = this.extractKid(token);
            
            // Get signing key
            const signingKey = this.publicKeys.signing[kid];
            if (!signingKey) {
                throw new Error(`Unknown key ID: ${kid}`);
            }
            
            // Verify certificate chain
            if (!await this.verifyCertificateChain(signingKey)) {
                throw new Error('Invalid certificate chain');
            }
            
            // Verify token
            const publicKey = await this.importPublicKey(signingKey.public_key);
            const payload = await V4.verify(token, publicKey);
            
            // Validate claims
            this.validateClaims(payload);
            
            return payload;
            
        } catch (error) {
            throw new Error(`Verification failed: ${error.message}`);
        }
    }
    
    validateClaims(claims) {
        const now = Math.floor(Date.now() / 1000);
        
        // Check expiration
        if (claims.exp && now > (claims.exp + this.clockSkew)) {
            throw new Error('Token expired');
        }
        
        // Check not before
        if (claims.nbf && now < (claims.nbf - this.clockSkew)) {
            throw new Error('Token not yet valid');
        }
        
        // Check force online
        if (claims.force_online_after) {
            const forceOnline = new Date(claims.force_online_after).getTime() / 1000;
            if (now > forceOnline) {
                throw new Error('Online validation required');
            }
        }
        
        // Check fingerprint
        const currentFingerprint = this.generateFingerprint();
        if (claims.usage_fingerprint !== currentFingerprint) {
            throw new Error('Device fingerprint mismatch');
        }
    }
    
    generateFingerprint() {
        // Browser fingerprinting
        const components = {
            userAgent: navigator.userAgent,
            language: navigator.language,
            platform: navigator.platform,
            hardwareConcurrency: navigator.hardwareConcurrency,
            screenResolution: `${screen.width}x${screen.height}`,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        };
        
        return this.hash(JSON.stringify(components));
    }
    
    async hash(data) {
        const encoder = new TextEncoder();
        const dataBuffer = encoder.encode(data);
        const hashBuffer = await crypto.subtle.digest('SHA-256', dataBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }
}
```

## Token Refresh Strategy

### Automatic Token Refresh

```php
class TokenRefreshService
{
    private int $refreshThreshold = 2; // days before expiration
    
    /**
     * Check if token needs refresh
     */
    public function needsRefresh(string $token): bool
    {
        try {
            $claims = $this->tokenService->extractClaims($token);
            
            $exp = Carbon::parse($claims['exp']);
            $daysUntilExpiration = now()->diffInDays($exp);
            
            return $daysUntilExpiration <= $this->refreshThreshold;
            
        } catch (\Exception $e) {
            return true; // Refresh if can't parse
        }
    }
    
    /**
     * Refresh token
     */
    public function refresh(string $oldToken): string
    {
        // Verify old token
        $claims = $this->tokenService->verify($oldToken);
        
        // Load license and usage
        $license = License::find($claims['license_id']);
        $usage = LicenseUsage::where('usage_fingerprint', $claims['usage_fingerprint'])
            ->where('license_id', $license->id)
            ->first();
        
        if (!$usage || !$usage->isActive()) {
            throw new \Exception('Usage not found or inactive');
        }
        
        // Update heartbeat
        $usage->heartbeat();
        
        // Issue new token
        return $this->tokenService->issue($license, $usage, [
            'ttl_days' => $license->getTokenTtlDays(),
        ]);
    }
    
    /**
     * Background refresh job
     */
    public function backgroundRefresh(): void
    {
        $storedToken = $this->storage->get('license_token');
        
        if (!$storedToken) {
            return;
        }
        
        if ($this->needsRefresh($storedToken)) {
            try {
                $newToken = $this->refresh($storedToken);
                $this->storage->set('license_token', $newToken);
                
                event(new TokenRefreshed($newToken));
                
            } catch (\Exception $e) {
                // Try online validation
                $this->requestOnlineValidation();
            }
        }
    }
}
```

## Security Considerations

### Clock Synchronization

```php
class ClockSyncService
{
    /**
     * Detect clock manipulation
     */
    public function detectClockManipulation(array $claims): bool
    {
        // Check issued at time
        if (isset($claims['iat'])) {
            $iat = Carbon::parse($claims['iat']);
            $serverTime = $this->getServerTime();
            
            $drift = abs($iat->diffInSeconds($serverTime));
            
            if ($drift > 300) { // 5 minutes
                return true; // Possible manipulation
            }
        }
        
        // Check against last known time
        $lastKnownTime = Cache::get('last_known_time');
        if ($lastKnownTime && now() < $lastKnownTime) {
            return true; // Clock went backwards
        }
        
        Cache::put('last_known_time', now(), 3600);
        
        return false;
    }
    
    /**
     * Get server time via API
     */
    private function getServerTime(): Carbon
    {
        try {
            $response = Http::get(config('licensing.time_server'));
            return Carbon::parse($response->json('time'));
        } catch (\Exception $e) {
            return now();
        }
    }
}
```

### Token Storage

```php
class SecureTokenStorage
{
    /**
     * Store token securely
     */
    public function store(string $token): void
    {
        // Encrypt token
        $encrypted = Crypt::encryptString($token);
        
        // Store with metadata
        $data = [
            'token' => $encrypted,
            'stored_at' => now(),
            'fingerprint' => $this->generateStorageFingerprint(),
        ];
        
        // Platform-specific storage
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows Credential Manager
            $this->storeWindows($data);
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS Keychain
            $this->storeMacOS($data);
        } else {
            // Linux Secret Service
            $this->storeLinux($data);
        }
    }
    
    /**
     * Retrieve token
     */
    public function retrieve(): ?string
    {
        $data = $this->platformRetrieve();
        
        if (!$data) {
            return null;
        }
        
        // Verify storage fingerprint
        if ($data['fingerprint'] !== $this->generateStorageFingerprint()) {
            throw new TamperException('Token storage tampered');
        }
        
        // Decrypt token
        return Crypt::decryptString($data['token']);
    }
}
```

## Force Online Validation

### Implementing Force Online Windows

```php
class ForceOnlineValidator
{
    /**
     * Check if online validation required
     */
    public function isOnlineRequired(array $claims): bool
    {
        // Check force online date
        if (isset($claims['force_online_after'])) {
            $forceDate = Carbon::parse($claims['force_online_after']);
            
            if (now() > $forceDate) {
                return true;
            }
        }
        
        // Check last online validation
        $lastOnline = Cache::get('last_online_validation');
        if (!$lastOnline) {
            return true;
        }
        
        $daysSinceOnline = now()->diffInDays($lastOnline);
        $maxOfflineDays = config('licensing.max_offline_days', 30);
        
        return $daysSinceOnline > $maxOfflineDays;
    }
    
    /**
     * Perform online validation
     */
    public function validateOnline(string $token): array
    {
        $response = Http::withToken($token)
            ->post(config('licensing.api_url') . '/validate', [
                'token' => $token,
                'fingerprint' => $this->generateFingerprint(),
            ]);
        
        if (!$response->successful()) {
            throw new OnlineValidationException(
                'Online validation failed: ' . $response->body()
            );
        }
        
        // Cache successful validation
        Cache::put('last_online_validation', now(), 86400 * 30);
        
        return $response->json();
    }
}
```

## Testing Offline Verification

### Unit Tests

```php
use Tests\TestCase;
use LucaLongo\Licensing\Services\PasetoTokenService;

class OfflineVerificationTest extends TestCase
{
    private PasetoTokenService $tokenService;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenService = app(PasetoTokenService::class);
    }
    
    public function test_token_generation_and_verification()
    {
        // Create license and usage
        $license = License::factory()->active()->create();
        $usage = LicenseUsage::factory()->for($license)->create();
        
        // Generate token
        $token = $this->tokenService->issue($license, $usage);
        
        // Verify token
        $claims = $this->tokenService->verify($token);
        
        $this->assertEquals($license->id, $claims['license_id']);
        $this->assertEquals($usage->usage_fingerprint, $claims['usage_fingerprint']);
    }
    
    public function test_expired_token_fails_verification()
    {
        $license = License::factory()->active()->create();
        $usage = LicenseUsage::factory()->for($license)->create();
        
        // Generate token with negative TTL (already expired)
        $token = $this->tokenService->issue($license, $usage, [
            'ttl_days' => -1,
        ]);
        
        $this->expectException(TokenExpiredException::class);
        $this->tokenService->verify($token);
    }
    
    public function test_token_with_revoked_key_fails()
    {
        $license = License::factory()->active()->create();
        $usage = LicenseUsage::factory()->for($license)->create();
        
        // Generate token
        $token = $this->tokenService->issue($license, $usage);
        
        // Revoke signing key
        $signingKey = LicensingKey::findActiveSigning();
        $signingKey->revoke('test');
        
        $this->expectException(KeyRevokedException::class);
        $this->tokenService->verify($token);
    }
    
    public function test_clock_skew_tolerance()
    {
        $license = License::factory()->active()->create();
        $usage = LicenseUsage::factory()->for($license)->create();
        
        // Generate token
        $token = $this->tokenService->issue($license, $usage);
        
        // Simulate clock drift (59 seconds)
        Carbon::setTestNow(now()->subSeconds(59));
        
        // Should still verify within tolerance
        $claims = $this->tokenService->verify($token);
        $this->assertNotNull($claims);
        
        // Simulate larger drift (61 seconds)
        Carbon::setTestNow(now()->subSeconds(61));
        
        // Should fail outside tolerance
        $this->expectException(ClockSkewException::class);
        $this->tokenService->verify($token);
    }
}
```

## Performance Optimization

### Token Caching

```php
class CachedTokenVerifier
{
    private int $cacheTtl = 300; // 5 minutes
    
    /**
     * Verify with caching
     */
    public function verify(string $token): array
    {
        $cacheKey = 'token:' . hash('sha256', $token);
        
        // Check cache
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }
        
        // Verify token
        $claims = $this->tokenService->verify($token);
        
        // Cache valid result
        Cache::put($cacheKey, $claims, $this->cacheTtl);
        
        return $claims;
    }
    
    /**
     * Batch verification
     */
    public function verifyBatch(array $tokens): array
    {
        $results = [];
        
        foreach ($tokens as $token) {
            try {
                $results[] = [
                    'token' => $token,
                    'valid' => true,
                    'claims' => $this->verify($token),
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'token' => $token,
                    'valid' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
}
```

## Next Steps

- [Key Management](../advanced/key-management.md) - Managing cryptographic keys
- [Security](../advanced/security.md) - Security best practices
- [Client Libraries](../client-libraries/offline-verification.md) - Client implementation
- [API Reference](../api/services.md#tokenservice) - Token service API