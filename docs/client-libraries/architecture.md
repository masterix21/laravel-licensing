# 📱 Client Library Architecture

This guide outlines the architectural principles and design patterns for building client libraries that integrate with Laravel Licensing in various programming languages.

## Core Principles

### 1. Separation of Concerns

Client libraries should be organized into distinct layers:

```
┌─────────────────────────────────────┐
│         Application Layer           │
├─────────────────────────────────────┤
│         License Manager              │
├─────────────────────────────────────┤
│    Verification │ Storage │ API     │
├─────────────────────────────────────┤
│      Cryptography │ Network         │
└─────────────────────────────────────┘
```

### 2. Offline-First Design

Client libraries should prioritize offline functionality:

- **Local token storage** with secure encryption
- **Offline verification** using cached public keys
- **Graceful degradation** when server is unreachable
- **Background synchronization** when connectivity returns

### 3. Security by Default

Every client library must implement:

- **Secure storage** for tokens and keys
- **Certificate pinning** for API communications
- **Tamper detection** for stored licenses
- **Time-based validation** with clock skew tolerance

## Essential Components

### 1. License Manager

The central coordinator that handles all licensing operations:

```python
class LicenseManager:
    def __init__(self, config: LicenseConfig):
        self.config = config
        self.verifier = TokenVerifier()
        self.storage = SecureStorage()
        self.api = LicenseAPI()
    
    def activate(self, key: str) -> License
    def validate(self) -> ValidationResult
    def refresh(self) -> None
    def get_features(self) -> List[Feature]
    def check_entitlement(self, key: str) -> Any
```

### 2. Token Verifier

Handles cryptographic verification of offline tokens:

```javascript
class TokenVerifier {
    constructor(publicKeyBundle) {
        this.keys = this.parseKeyBundle(publicKeyBundle);
        this.clockSkew = 60; // seconds
    }
    
    verify(token) {
        // 1. Parse token format (PASETO/JWS)
        // 2. Verify signature with public key
        // 3. Validate certificate chain
        // 4. Check expiration with clock skew
        // 5. Verify claims (license_id, fingerprint, etc.)
    }
    
    verifyOffline(token, publicKey) {
        // Offline verification without server
    }
}
```

### 3. Secure Storage

Platform-specific secure storage implementation:

```java
public class SecureStorage {
    // Windows: DPAPI
    // macOS: Keychain
    // Linux: Secret Service API
    // Mobile: iOS Keychain / Android Keystore
    
    public void storeLicense(License license) {
        String encrypted = encrypt(license);
        platformStore.save("license", encrypted);
    }
    
    public License retrieveLicense() {
        String encrypted = platformStore.get("license");
        return decrypt(encrypted);
    }
}
```

### 4. Device Fingerprinting

Consistent device identification across platforms:

```go
type FingerprintGenerator struct {
    components []FingerprintComponent
}

func (f *FingerprintGenerator) Generate() string {
    data := map[string]string{
        "mac_address": getPrimaryMAC(),
        "machine_id":  getMachineID(),
        "cpu_id":      getCPUID(),
        "disk_serial": getDiskSerial(),
    }
    
    // Create stable hash
    return sha256(serialize(data))
}
```

### 5. API Client

RESTful API client with retry logic and rate limiting:

```csharp
public class LicenseAPIClient
{
    private readonly HttpClient httpClient;
    private readonly RetryPolicy retryPolicy;
    
    public async Task<License> ActivateLicense(string key)
    {
        var request = new ActivationRequest { Key = key };
        
        return await retryPolicy.ExecuteAsync(async () =>
        {
            var response = await httpClient.PostAsync(
                "/api/licensing/v1/activate",
                JsonContent(request)
            );
            
            return ParseResponse<License>(response);
        });
    }
}
```

## Platform-Specific Considerations

### Desktop Applications

#### Windows
- Use **Windows Credential Manager** for token storage
- Leverage **DPAPI** for encryption
- Registry for configuration storage
- WMI for hardware fingerprinting

#### macOS
- Use **Keychain Services** for secure storage
- **System Configuration Framework** for hardware info
- Application Support directory for cache
- Code signing for integrity

#### Linux
- **Secret Service API** (GNOME Keyring/KWallet)
- DBus for system information
- XDG directories for storage
- Package manager integration

### Mobile Applications

#### iOS
- **Keychain Services** for secure storage
- **DeviceCheck** for device attestation
- Background fetch for token refresh
- App Transport Security compliance

#### Android
- **Android Keystore** for cryptographic operations
- **SafetyNet Attestation** for device verification
- WorkManager for background tasks
- Certificate pinning with Network Security Config

### Web Applications

#### Browser JavaScript
- **Web Crypto API** for cryptographic operations
- **IndexedDB** with encryption for storage
- Service Workers for offline functionality
- SubtleCrypto for token verification

#### Node.js
- **node-forge** or **sodium-native** for crypto
- OS-specific keyring libraries
- Worker threads for verification
- Cluster module for scaling

## Implementation Patterns

### 1. Lazy Initialization

Initialize licensing only when needed:

```rust
struct LicenseManager {
    license: OnceCell<License>,
}

impl LicenseManager {
    fn get_license(&self) -> Result<&License> {
        self.license.get_or_try_init(|| {
            self.load_or_activate()
        })
    }
}
```

### 2. Automatic Refresh

Background refresh before expiration:

```swift
class LicenseRefreshScheduler {
    func scheduleRefresh(for license: License) {
        let refreshDate = license.expiresAt.addingTimeInterval(-86400) // 1 day before
        
        let task = BGProcessingTask(identifier: "license.refresh") {
            await self.refreshLicense()
        }
        
        BGTaskScheduler.shared.submit(task, at: refreshDate)
    }
}
```

### 3. Graceful Degradation

Handle offline scenarios elegantly:

```kotlin
class LicenseValidator {
    fun validate(): ValidationResult {
        return try {
            // Try online validation first
            validateOnline()
        } catch (e: NetworkException) {
            // Fall back to offline validation
            validateOffline()
        } catch (e: Exception) {
            // Use grace period if available
            checkGracePeriod()
        }
    }
}
```

### 4. Feature Flags

Efficient feature checking:

```ruby
class FeatureManager
  def initialize(license)
    @features = license.features.to_set
    @entitlements = license.entitlements
  end
  
  def enabled?(feature)
    @features.include?(feature)
  end
  
  def get_limit(entitlement)
    @entitlements[entitlement] || 0
  end
  
  def with_feature(feature, &block)
    yield if enabled?(feature)
  end
end
```

## Error Handling

### Error Categories

1. **Recoverable Errors** - Retry with backoff
   - Network timeouts
   - Rate limiting
   - Temporary server errors

2. **User Errors** - Prompt for action
   - Invalid activation key
   - License expired
   - Usage limit reached

3. **Fatal Errors** - Graceful shutdown
   - Tampered license
   - Clock manipulation detected
   - Critical validation failure

### Error Response Structure

```typescript
interface LicenseError {
    code: string;           // Machine-readable error code
    message: string;        // User-friendly message
    type: ErrorType;        // Category of error
    retry_after?: number;   // Seconds to wait before retry
    hint?: string;          // Helpful suggestion
    metadata?: any;         // Additional context
}
```

## Testing Strategy

### 1. Mock Server

Implement a mock licensing server for testing:

```python
class MockLicenseServer:
    def __init__(self):
        self.licenses = {}
        self.responses = {}
    
    def set_response(self, endpoint, response):
        self.responses[endpoint] = response
    
    def simulate_offline(self):
        self.online = False
    
    def simulate_expired_license(self):
        self.licenses['test'].expires_at = datetime.now() - timedelta(days=1)
```

### 2. Time Manipulation

Test time-based features:

```java
public class TimeProvider {
    private static Clock clock = Clock.systemDefaultZone();
    
    public static void setClock(Clock newClock) {
        clock = newClock;
    }
    
    public static Instant now() {
        return Instant.now(clock);
    }
}

// In tests
TimeProvider.setClock(Clock.fixed(testTime, ZoneOffset.UTC));
```

### 3. Platform Mocking

Mock platform-specific APIs:

```javascript
class PlatformMock {
    constructor() {
        this.storage = new Map();
        this.fingerprint = 'test-fingerprint';
    }
    
    getFingerprint() {
        return this.fingerprint;
    }
    
    storeSecure(key, value) {
        this.storage.set(key, value);
    }
}
```

## Performance Optimization

### 1. Caching Strategy

- Cache validation results for 5 minutes
- Cache feature flags until license refresh
- Cache public keys until expiration
- Use LRU cache for API responses

### 2. Async Operations

- Non-blocking license validation
- Background token refresh
- Parallel API calls where possible
- Queue-based retry mechanism

### 3. Resource Management

- Connection pooling for API calls
- Lazy loading of cryptographic libraries
- Efficient memory usage for token storage
- Minimal CPU usage during idle

## Deployment Considerations

### 1. Versioning

Follow semantic versioning:
- **Major**: Breaking API changes
- **Minor**: New features, backward compatible
- **Patch**: Bug fixes

### 2. Distribution

- **Package Managers**: NPM, PyPI, NuGet, Maven, etc.
- **Binary Releases**: GitHub Releases with checksums
- **Source Distribution**: Include build instructions
- **Docker Images**: For containerized environments

### 3. Documentation

Essential documentation:
- Quick start guide
- API reference
- Platform-specific notes
- Migration guides
- Troubleshooting section

## Security Checklist

- [ ] Secure storage implementation
- [ ] Certificate pinning enabled
- [ ] Tamper detection active
- [ ] Clock manipulation protection
- [ ] Secure random number generation
- [ ] No hardcoded secrets
- [ ] Proper error message sanitization
- [ ] Rate limiting implementation
- [ ] Audit logging capability
- [ ] Update mechanism security

## Example Architecture: Desktop App

```
┌──────────────────────────────────────────┐
│            Desktop Application           │
├──────────────────────────────────────────┤
│          License Manager API             │
│  ┌────────────┬─────────┬────────────┐  │
│  │ Activation │ Validation│ Features  │  │
│  └────────────┴─────────┴────────────┘  │
├──────────────────────────────────────────┤
│           Core Components                │
│  ┌──────────────┬──────────────────┐    │
│  │ Token Verifier│ Secure Storage   │    │
│  ├──────────────┼──────────────────┤    │
│  │ API Client   │ Fingerprint Gen   │    │
│  └──────────────┴──────────────────┘    │
├──────────────────────────────────────────┤
│         Platform Abstraction             │
│  ┌──────────────┬──────────────────┐    │
│  │ OS Storage   │ Hardware Info     │    │
│  ├──────────────┼──────────────────┤    │
│  │ Crypto APIs  │ Network Layer     │    │
│  └──────────────┴──────────────────┘    │
└──────────────────────────────────────────┘
```

## Next Steps

- Review [Implementation Guide](implementation-guide.md) for language-specific details
- Check [API Integration](api-integration.md) for server communication
- Read [Offline Verification](offline-verification.md) for token handling
- See [Practical Examples](../examples/practical-examples.md) for real implementations