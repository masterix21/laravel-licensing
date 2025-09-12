# Laravel Licensing - Client Implementation Guide

## Purpose
This document provides a complete specification for implementing Laravel Licensing clients in any programming language or architecture. It serves as a reference for AI assistants or developers to create compatible client libraries.

---

## Core Concepts

### 1. License Lifecycle from Client Perspective
```
Activation → Token Receipt → Offline Validation → Periodic Refresh → Expiration/Renewal
```

### 2. Client Responsibilities
- Generate stable device fingerprint
- Store tokens securely
- Validate tokens offline
- Refresh tokens before expiration
- Handle grace periods
- Enforce license restrictions

---

## Required Client Components

### 1. Fingerprint Generator
**Purpose**: Create a unique, stable identifier for the device/installation

**Requirements**:
- MUST be deterministic (same device = same fingerprint)
- MUST NOT contain PII (personally identifiable information)
- MUST be reproducible after app restart
- SHOULD survive minor system changes

**Implementation**:
```
fingerprint = SHA256(
    hardware_id +
    mac_address +
    cpu_id +
    installation_path +
    app_identifier
)
```

**Platform-specific sources**:
- **Windows**: Registry MachineGuid, Volume Serial, CPU ID
- **macOS**: IOPlatformUUID, Hardware UUID
- **Linux**: /etc/machine-id, DMI product UUID
- **Docker**: Container ID + Host fingerprint
- **Web**: LocalStorage ID + Canvas fingerprint
- **Mobile**: Device ID + App Installation ID

---

### 2. Token Storage
**Purpose**: Securely store offline tokens and metadata

**Storage Requirements**:
```json
{
    "token": "v2.public.eyJ...",
    "public_key_bundle": {
        "root": {
            "kid": "kid_xxx",
            "public_key": "-----BEGIN PUBLIC KEY-----..."
        }
    },
    "cached_at": "2024-01-01T00:00:00Z",
    "last_verified": "2024-01-01T00:00:00Z",
    "last_online_check": "2024-01-01T00:00:00Z"
}
```

**Security Requirements**:
- MUST encrypt token at rest (using OS keychain/credential manager)
- MUST protect against tampering
- SHOULD use platform-specific secure storage:
  - Windows: Credential Manager / DPAPI
  - macOS: Keychain
  - Linux: libsecret / gnome-keyring
  - Mobile: iOS Keychain / Android Keystore
  - Web: IndexedDB with Web Crypto API

---

### 3. PASETO Token Verifier
**Purpose**: Validate tokens offline without server contact

**PASETO v2.public Token Structure**:
```
v2.public.<payload>.<footer>
```

**Verification Steps**:
1. Parse token into parts
2. Extract footer (contains kid and certificate chain)
3. Verify certificate chain against root public key
4. Verify token signature using signing public key
5. Check token claims

**Required Claim Validations**:
```json
{
    "iat": "2024-01-01T00:00:00Z",    // Issued at - check not future
    "nbf": "2024-01-01T00:00:00Z",    // Not before - check if valid
    "exp": "2024-01-08T00:00:00Z",    // Expiration - check not expired
    "sub": "123",                      // License ID
    "iss": "laravel-licensing",        // Issuer - must match config
    "license_id": 123,
    "license_key_hash": "sha256...",
    "usage_fingerprint": "sha256...",  // Must match current device
    "status": "active",                 // Must be active or grace
    "max_usages": 5,
    "force_online_after": "2024-01-14T00:00:00Z",
    "licensable_type": "App\\Models\\Team",
    "licensable_id": 456,
    "license_expires_at": "2024-12-31T23:59:59Z", // Optional
    "grace_until": "2024-01-14T23:59:59Z"         // Optional
}
```

**Validation Rules**:
- Token not expired (`exp` > now)
- Token is valid (`nbf` <= now)
- Fingerprint matches current device
- Status is usable (active or grace)
- Not past force_online_after date
- Clock skew tolerance: ±60 seconds

---

### 4. License Activator
**Purpose**: Exchange activation key for offline token

**API Request**:
```http
POST /api/licensing/v1/activate
Content-Type: application/json

{
    "license_key": "XXXX-XXXX-XXXX-XXXX",
    "fingerprint": "sha256_hash",
    "metadata": {
        "client_type": "desktop",
        "name": "John's MacBook",
        "app_version": "1.0.0",
        "os": "macOS 14.0"
    }
}
```

**API Response (Success)**:
```json
{
    "success": true,
    "data": {
        "token": "v2.public.eyJ...",
        "public_key_bundle": {...},
        "refresh_after": "2024-01-07T00:00:00Z",
        "force_online_after": "2024-01-14T00:00:00Z"
    }
}
```

**API Response (Error)**:
```json
{
    "success": false,
    "error": {
        "code": "USAGE_LIMIT_REACHED",
        "message": "License has reached maximum usages",
        "hint": "Deactivate another device first"
    }
}
```

**Error Codes**:
- `INVALID_KEY`: License key not found or invalid
- `EXPIRED_LICENSE`: License has expired
- `SUSPENDED_LICENSE`: License is suspended
- `USAGE_LIMIT_REACHED`: Max usages exceeded
- `FINGERPRINT_MISMATCH`: Fingerprint validation failed
- `RATE_LIMITED`: Too many requests

---

### 5. Token Refresher
**Purpose**: Get new token before expiration

**Refresh Strategy**:
```
if (token_expires_in < 24_hours || force_online_soon) {
    try {
        new_token = refresh_online()
        store(new_token)
    } catch (NetworkError) {
        if (token_still_valid) {
            continue_offline()
        } else {
            block_access()
        }
    }
}
```

**API Request**:
```http
POST /api/licensing/v1/refresh
Authorization: Bearer <current_token>

{
    "fingerprint": "sha256_hash"
}
```

---

### 6. Heartbeat Reporter
**Purpose**: Update last_seen_at to maintain active status

**When to Send**:
- On application startup
- Every 24 hours while running
- Before extended offline period

**API Request**:
```http
POST /api/licensing/v1/heartbeat
Authorization: Bearer <current_token>

{
    "fingerprint": "sha256_hash",
    "metadata": {
        "app_version": "1.0.1",
        "last_used_features": ["feature_a", "feature_b"]
    }
}
```

---

### 7. License Enforcer (Middleware/Interceptor)
**Purpose**: Block access to protected features

**Pseudocode**:
```python
def require_license(feature=None):
    license = load_cached_license()
    
    # Step 1: Verify token offline
    if not verify_token_offline(license.token):
        return block_access("Invalid or expired license")
    
    # Step 2: Check force online
    if past_force_online_date(license):
        if not refresh_license_online():
            return block_access("Online verification required")
    
    # Step 3: Check feature entitlements (if applicable)
    if feature and feature not in license.entitlements:
        return block_access(f"Feature {feature} not licensed")
    
    # Step 4: Check grace period
    if license.status == "grace":
        show_warning(f"License expires in {license.grace_days_remaining} days")
    
    return allow_access()
```

---

## Implementation Patterns

### 1. Initialization Flow
```
Application Start
    ↓
Load Cached Token
    ↓
Verify Offline → [Valid] → Check Force Online
    ↓                            ↓
[Invalid]                   [Needed]
    ↓                            ↓
Prompt for Key            Refresh Online
    ↓                            ↓
Activate Online           Update Cache
    ↓                            ↓
Store Token               Continue
```

### 2. Periodic Validation Flow
```
Every Hour/Day
    ↓
Check Token Expiry
    ↓
[Expires Soon?]
    ↓
Yes → Try Refresh → [Success] → Update Cache
            ↓
         [Fail] → Continue if Valid
```

### 3. Error Recovery Flow
```
Validation Failed
    ↓
[Network Available?]
    ↓                    ↓
Yes                    No
    ↓                    ↓
Refresh Online    Check Grace Period
    ↓                    ↓
[Success?]         [In Grace?]
    ↓                    ↓
Update          Allow with Warning
    ↓                    ↓
Continue            Block after Grace
```

---

## Platform-Specific Considerations

### Desktop Applications
- Store tokens in OS credential manager
- Generate fingerprint from hardware IDs
- Show system tray notifications for expiry
- Handle sleep/wake for heartbeat

### Web Applications
- Use IndexedDB for token storage
- Implement service worker for offline validation
- Handle tab visibility for heartbeat
- Use Web Crypto API for verification

### Mobile Applications
- Use platform keychain/keystore
- Handle app backgrounding
- Request network permission for refresh
- Implement push notifications for expiry

### Server/CLI Applications
- Use filesystem with permissions 600
- Generate fingerprint from container/VM ID
- Implement systemd timer for heartbeat
- Log validation failures to syslog

### Docker/Kubernetes
- Mount license as secret
- Use pod/container ID in fingerprint
- Implement init container for activation
- Use liveness probe for validation

---

## Security Requirements

### Token Protection
1. Never log full tokens
2. Encrypt tokens at rest
3. Validate token integrity
4. Implement rate limiting on validation
5. Clear tokens on deactivation

### Network Security
1. Always use HTTPS for API calls
2. Implement certificate pinning (optional)
3. Validate server certificates
4. Handle man-in-the-middle attacks
5. Implement exponential backoff

### Fingerprint Privacy
1. Hash all hardware IDs
2. Never send raw hardware info
3. Allow user to view fingerprint
4. Document what data is collected
5. Comply with GDPR/privacy laws

---

## Testing Requirements

### Unit Tests
1. Fingerprint generation consistency
2. Token parsing and validation
3. Clock skew handling
4. Grace period calculation
5. Error response handling

### Integration Tests
1. Full activation flow
2. Token refresh flow
3. Offline validation
4. Force online handling
5. Network failure recovery

### Platform Tests
1. Secure storage access
2. Hardware ID availability
3. Network permission handling
4. Background task execution
5. UI notification display

---

## Minimum Viable Client

A minimal client MUST implement:

1. **Fingerprint generation** (stable device ID)
2. **Token storage** (encrypted)
3. **Offline PASETO verification** (with RSA)
4. **License activation** (exchange key for token)
5. **Basic enforcement** (block if invalid)

Optional features for enhanced functionality:
- Token refresh
- Heartbeat reporting
- Grace period handling
- Feature-level enforcement
- Audit logging
- Telemetry collection

---

## Reference Implementations

### Minimal Python Client
```python
import hashlib
import json
from datetime import datetime
from paseto import PasetoV2
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

class LicenseClient:
    def __init__(self, server_url, public_key_bundle):
        self.server_url = server_url
        self.public_key = self.load_public_key(public_key_bundle)
        self.token = None
    
    def generate_fingerprint(self):
        # Platform-specific implementation
        hardware_id = self.get_hardware_id()
        return hashlib.sha256(hardware_id.encode()).hexdigest()
    
    def activate(self, license_key):
        fingerprint = self.generate_fingerprint()
        response = requests.post(
            f"{self.server_url}/activate",
            json={
                "license_key": license_key,
                "fingerprint": fingerprint
            }
        )
        if response.ok:
            self.token = response.json()["data"]["token"]
            self.save_token(self.token)
            return True
        return False
    
    def verify(self):
        if not self.token:
            self.token = self.load_token()
        
        try:
            # Verify PASETO token
            payload = PasetoV2.verify(
                self.token,
                self.public_key,
                options={"verify_exp": True}
            )
            
            # Check fingerprint
            if payload["usage_fingerprint"] != self.generate_fingerprint():
                return False
            
            # Check force online
            force_online = datetime.fromisoformat(payload["force_online_after"])
            if datetime.now() > force_online:
                return self.refresh()
            
            return payload["status"] in ["active", "grace"]
            
        except Exception:
            return False
    
    def require_license(self, func):
        def wrapper(*args, **kwargs):
            if not self.verify():
                raise Exception("Invalid license")
            return func(*args, **kwargs)
        return wrapper
```

---

## Compliance & Legal

### Data Collection
Clients MUST:
- Document all data collected for fingerprinting
- Provide privacy policy compliance
- Allow users to view collected data
- Implement data deletion on request
- Comply with GDPR Article 17 (Right to Erasure)

### Export Controls
- Implement region restrictions if required
- Check license against embargo lists
- Log access attempts from restricted regions

### Audit Trail
- Log all license validations
- Record activation/deactivation events
- Track feature usage if required
- Implement tamper-evident logging

---

## Support & Troubleshooting

### Common Issues
1. **Clock Skew**: System time off by >60 seconds
2. **Fingerprint Drift**: Hardware changes causing mismatch
3. **Token Corruption**: Storage failure or tampering
4. **Network Isolation**: Can't refresh when required
5. **Permission Issues**: Can't access secure storage

### Diagnostic Information
Clients SHOULD provide debug command/UI showing:
- Current fingerprint
- Token expiration
- Last online check
- License status
- Error logs
- Public key bundle

### Recovery Mechanisms
- Manual token refresh command
- License reset option
- Fingerprint regeneration
- Offline activation via file
- Emergency grace period extension

---

## Version Compatibility

### Protocol Version
Current: `v1`
Token Format: `PASETO v2.public`

### Breaking Changes
Clients MUST handle:
- New required claims in tokens
- Changed API endpoints
- Updated crypto algorithms
- Modified fingerprint requirements

### Deprecation Policy
- 6 months notice for breaking changes
- 12 months support for old protocol
- Clear migration guides
- Backward compatibility when possible