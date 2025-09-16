# Key Management

Cryptographic key management is critical for offline verification security. This document covers the complete key lifecycle from generation to revocation.

## Key Architecture

### Two-Level Hierarchy

```
Root Key (Long-lived, 2+ years)
├── Signing Key 1 (Active, 90 days)
├── Signing Key 2 (Standby)
└── Signing Key 3 (Revoked)
```

### Key Types

**Root Keys:**
- Long-lived trust anchors
- Sign signing key certificates only
- Never sign tokens directly
- Stored offline when possible

**Signing Keys:**
- Short-lived operational keys
- Sign offline verification tokens
- Regularly rotated (90 days default)
- Include validity periods

## Key Generation

### Root Key Creation

```bash
# Generate root key with strong passphrase
php artisan licensing:keys:make-root \
  --algorithm=ed25519 \
  --passphrase="${LICENSING_ROOT_PASSPHRASE}"
```

```php
// Programmatic root key generation
$caService = app(CertificateAuthorityService::class);
$rootKeyId = $caService->generateRootKey();

// Root key is automatically:
// 1. Generated with ed25519 algorithm
// 2. Private key encrypted with passphrase
// 3. Stored in configured keystore
// 4. Public key exported for distribution
```

### Signing Key Issuance

```bash
# Issue new signing key
php artisan licensing:keys:issue-signing \
  --kid="signing-2024-q1" \
  --nbf="2024-01-01T00:00:00Z" \
  --exp="2024-04-01T00:00:00Z"
```

```php
// Programmatic signing key issuance
$signingKey = $caService->issueSigningKey(
    'signing-' . now()->format('Y-m-d'),
    now(),
    now()->addDays(90)
);

// Signing key certificate includes:
// - Key identifier (kid)
// - Validity period (nbf/exp)
// - Root key signature
// - Certificate chain
```

## Key Rotation

### Scheduled Rotation

```php
// Automated rotation job
class RotateSigningKeysJob implements ShouldQueue
{
    public function handle(CertificateAuthorityService $ca): void
    {
        $activeKey = $ca->getActiveSigningKey();
        
        // Rotate if key is older than 60 days
        if ($activeKey && $activeKey->created_at->diffInDays() > 60) {
            $newKey = $ca->rotateSigningKey('scheduled');
            
            // Update public key distribution
            $this->updatePublicKeyDistribution($newKey);
            
            // Notify administrators
            $this->notifyKeyRotation($activeKey, $newKey);
        }
    }
    
    private function updatePublicKeyDistribution(LicensingKey $newKey): void
    {
        $bundle = $ca->exportPublicKeys('json');
        
        // Update CDN or public endpoints
        Storage::disk('public')->put('licensing/keys.json', $bundle);
        
        // Invalidate caches
        Cache::tags(['licensing-keys'])->flush();
    }
}
```

> Tip: Configure the passphrase once in `config/licensing.php` (`licensing.crypto.keystore.passphrase` or `passphrase_env`). The framework caches the resolved value for the request lifecycle so repeated encrypt/decrypt operations avoid additional environment lookups.

### Emergency Rotation

```bash
# Emergency rotation for compromised keys
php artisan licensing:keys:rotate \
  --reason=compromised \
  --force \
  --revoke-at=now
```

```php
// Emergency rotation procedure
class EmergencyKeyRotation
{
    public function handleCompromise(string $compromisedKid): void
    {
        $ca = app(CertificateAuthorityService::class);
        
        // Immediately revoke compromised key
        $ca->revokeKey($compromisedKid, now());
        
        // Issue new signing key
        $newKey = $ca->issueSigningKey(
            'emergency-' . now()->format('YmdHis'),
            now(),
            now()->addDays(90)
        );
        
        // Update public key distribution immediately
        $this->forcePublicKeyUpdate();
        
        // Alert all stakeholders
        $this->sendSecurityAlert($compromisedKid, $newKey->kid);
        
        // Audit the incident
        app(AuditLogger::class)->log(
            AuditEventType::KeyCompromised,
            null,
            [
                'compromised_kid' => $compromisedKid,
                'new_kid' => $newKey->kid,
                'incident_id' => Str::uuid(),
            ]
        );
    }
}
```

## Key Storage

### File-Based Storage

```php
// Default file-based keystore
class FileKeyStore implements KeyStore
{
    public function store(string $kid, array $keyData): bool
    {
        $keyPath = $this->getKeyPath($kid);
        
        // Encrypt private key with passphrase
        if (isset($keyData['private_key'])) {
            $keyData['private_key'] = $this->encryptPrivateKey(
                $keyData['private_key']
            );
        }
        
        // Store with restrictive permissions
        $result = file_put_contents(
            $keyPath, 
            json_encode($keyData),
            LOCK_EX
        );
        
        if ($result) {
            chmod($keyPath, 0600); // Owner read/write only
        }
        
        return $result !== false;
    }
    
    private function encryptPrivateKey(string $privateKey): string
    {
        $passphrase = $this->getPassphrase();
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, 10000, 32, true);
        $nonce = random_bytes(12);
        
        $encrypted = sodium_crypto_aead_aes256gcm_encrypt(
            $privateKey,
            '',
            $nonce,
            $key
        );
        
        return base64_encode($salt . $nonce . $encrypted);
    }
}
```

### Database Storage

```php
// Database keystore for distributed environments
class DatabaseKeyStore implements KeyStore
{
    public function store(string $kid, array $keyData): bool
    {
        return LicensingKey::updateOrCreate(
            ['kid' => $kid],
            [
                'type' => $keyData['type'],
                'status' => $keyData['status'],
                'public_key' => $keyData['public_key'],
                'private_key' => isset($keyData['private_key']) 
                    ? $this->encryptPrivateKey($keyData['private_key'])
                    : null,
                'not_before' => $keyData['not_before'] ?? null,
                'not_after' => $keyData['not_after'] ?? null,
                'meta' => $keyData['meta'] ?? null,
            ]
        ) !== null;
    }
}
```

### Hardware Security Module (HSM)

```php
// HSM integration for production environments
class HsmKeyStore implements KeyStore
{
    public function __construct(
        private HsmClient $hsmClient,
        private string $hsmPartition
    ) {}
    
    public function store(string $kid, array $keyData): bool
    {
        // Store private key in HSM
        if (isset($keyData['private_key'])) {
            $hsmKeyId = $this->hsmClient->importKey(
                $this->hsmPartition,
                $kid,
                $keyData['private_key']
            );
            
            // Store only HSM reference, not actual key
            $keyData['hsm_key_id'] = $hsmKeyId;
            unset($keyData['private_key']);
        }
        
        // Store metadata in database
        return LicensingKey::updateOrCreate(['kid' => $kid], $keyData) !== null;
    }
    
    public function sign(string $kid, string $data): string
    {
        $key = $this->retrieve($kid);
        
        return $this->hsmClient->sign(
            $key['hsm_key_id'],
            $data,
            'ed25519'
        );
    }
}
```

## Key Distribution

### Public Key Bundle

```php
// Generate public key bundle for client distribution
class PublicKeyDistribution
{
    public function generateBundle(): array
    {
        $ca = app(CertificateAuthorityService::class);
        
        return [
            'version' => '1.0',
            'generated_at' => now()->toISOString(),
            'issuer' => config('licensing.offline_token.issuer'),
            'keys' => $this->getActiveKeys(),
            'root_certificate' => $this->getRootCertificate(),
        ];
    }
    
    private function getActiveKeys(): array
    {
        return LicensingKey::where('status', KeyStatus::Active)
            ->whereNotNull('public_key')
            ->get()
            ->map(fn($key) => [
                'kid' => $key->kid,
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => base64url_encode($this->extractPublicKeyBytes($key->public_key)),
                'use' => 'sig',
                'alg' => 'EdDSA',
            ])
            ->toArray();
    }
}
```

### Automatic Distribution

```php
// Automatic key distribution endpoint
Route::get('/api/licensing/v1/keys', function () {
    $distribution = app(PublicKeyDistribution::class);
    $bundle = $distribution->generateBundle();
    
    return response()->json($bundle)
        ->header('Cache-Control', 'public, max-age=3600')
        ->header('Content-Type', 'application/json');
});
```

## Key Monitoring

### Health Checks

```php
class KeyHealthMonitor
{
    public function checkKeyHealth(): array
    {
        $issues = [];
        
        // Check for active signing key
        $activeSigningKey = LicensingKey::where('type', KeyType::Signing)
            ->where('status', KeyStatus::Active)
            ->first();
            
        if (!$activeSigningKey) {
            $issues[] = 'No active signing key found';
        } elseif ($activeSigningKey->not_after < now()->addDays(7)) {
            $issues[] = 'Active signing key expires within 7 days';
        }
        
        // Check root key
        $rootKey = LicensingKey::where('type', KeyType::Root)
            ->where('status', KeyStatus::Active)
            ->first();
            
        if (!$rootKey) {
            $issues[] = 'No root key found';
        }
        
        // Check for excessive revoked keys
        $revokedCount = LicensingKey::where('status', KeyStatus::Revoked)
            ->where('revoked_at', '>', now()->subDays(30))
            ->count();
            
        if ($revokedCount > 3) {
            $issues[] = "Excessive key revocations: {$revokedCount} in last 30 days";
        }
        
        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'active_keys' => LicensingKey::where('status', KeyStatus::Active)->count(),
            'next_rotation' => $activeSigningKey?->not_after,
        ];
    }
}
```

Proper key management ensures the long-term security and reliability of the offline verification system while providing operational flexibility for key rotation and recovery scenarios.
