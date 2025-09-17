# 💻 Practical Examples

Real-world implementation examples for common licensing scenarios.

## Table of Contents

1. [SaaS Application with Tiered Licensing](#saas-application-with-tiered-licensing)
2. [Desktop Software with Offline Verification](#desktop-software-with-offline-verification)
3. [Mobile App with Device Limits](#mobile-app-with-device-limits)
4. [API Service with Usage Quotas](#api-service-with-usage-quotas)
5. [Trial to Paid Conversion Flow](#trial-to-paid-conversion-flow)
6. [Enterprise Multi-Seat Licensing](#enterprise-multi-seat-licensing)
7. [Plugin/Extension Licensing](#pluginextension-licensing)
8. [Subscription with Auto-Renewal](#subscription-with-auto-renewal)

---

## SaaS Application with Tiered Licensing

Complete implementation for a SaaS with Basic, Pro, and Enterprise tiers.

### 1. Database Setup

```php
// database/migrations/create_license_templates.php
Schema::create('license_templates', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignId('license_scope_id')->nullable()->constrained('license_scopes')->nullOnDelete();
    $table->string('name');
    $table->string('slug')->unique();
    $table->integer('tier_level');
    $table->json('base_configuration');
    $table->json('features');
    $table->json('entitlements');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 2. Create License Tiers

```php
// database/seeders/LicenseTierSeeder.php
use LucaLongo\Licensing\Models\LicenseTemplate;
use LucaLongo\Licensing\Models\LicenseScope;

class LicenseTierSeeder extends Seeder
{
    public function run()
    {
        $scope = LicenseScope::firstOrCreate(
            ['slug' => 'saas-app'],
            ['name' => 'SaaS App']
        );

        // Basic Tier
        LicenseTemplate::create([
            'license_scope_id' => $scope->id,
            'name' => 'Basic Plan',
            'slug' => 'basic-monthly',
            'tier_level' => 1,
            'base_configuration' => [
                'max_usages' => 2,
                'validity_days' => 30,
                'grace_days' => 7,
            ],
            'features' => [
                'basic_reports' => true,
                'email_support' => true,
            ],
            'entitlements' => [
                'api_calls_per_day' => 1000,
                'storage_gb' => 10,
                'team_members' => 3,
            ],
        ]);

        // Pro Tier
        LicenseTemplate::create([
            'license_scope_id' => $scope->id,
            'name' => 'Professional Plan',
            'slug' => 'pro-monthly',
            'tier_level' => 2,
            'base_configuration' => [
                'max_usages' => 5,
                'validity_days' => 30,
                'grace_days' => 14,
            ],
            'features' => [
                'basic_reports' => true,
                'advanced_analytics' => true,
                'api_access' => true,
                'priority_support' => true,
                'custom_branding' => true,
            ],
            'entitlements' => [
                'api_calls_per_day' => 10000,
                'storage_gb' => 100,
                'team_members' => 10,
            ],
        ]);

        // Enterprise Tier
        LicenseTemplate::create([
            'license_scope_id' => $scope->id,
            'name' => 'Enterprise Plan',
            'slug' => 'enterprise-annual',
            'tier_level' => 3,
            'base_configuration' => [
                'max_usages' => -1, // Unlimited
                'validity_days' => 365,
                'grace_days' => 30,
            ],
            'features' => [
                'basic_reports' => true,
                'advanced_analytics' => true,
                'api_access' => true,
                'priority_support' => true,
                'custom_branding' => true,
                'white_label' => true,
                'sso_integration' => true,
                'audit_logs' => true,
                'dedicated_support' => true,
            ],
            'entitlements' => [
                'api_calls_per_day' => -1, // Unlimited
                'storage_gb' => 1000,
                'team_members' => -1, // Unlimited
            ],
        ]);
    }
}
```

### 3. License Creation Service

```php
// app/Services/SaaSLicensingService.php
namespace App\Services;

use LucaLongo\Licensing\Models\{License, LicenseScope, LicenseTemplate};
use LucaLongo\Licensing\Services\TemplateService;
use App\Models\Organization;
use Illuminate\Support\Str;

class SaaSLicensingService
{
    public function __construct(private TemplateService $templates) {}

    public function createLicenseForOrganization(
        Organization $org,
        LicenseScope $scope,
        string|LicenseTemplate $plan,
        array $options = []
    ): License {
        $activationKey = $this->generateActivationKey();

        $license = $this->templates->createLicenseForScope($scope, $plan, [
            'key_hash' => License::hashKey($activationKey),
            'licensable_type' => Organization::class,
            'licensable_id' => $org->id,
            'meta' => array_merge([
                'organization_name' => $org->name,
                'billing_email' => $org->billing_email,
                'created_by' => auth()->id(),
            ], $options),
        ]);

        $org->update([
            'activation_key' => encrypt($activationKey),
        ]);

        Mail::to($org->billing_email)->send(
            new LicenseCreatedMail($license, $activationKey)
        );

        return $license;
    }

    private function generateActivationKey(): string
    {
        return implode('-', str_split(
            strtoupper(Str::random(20)),
            4
        ));
    }

    public function upgradeLicense(License $license, LicenseTemplate $newTemplate): License
    {
        $currentTemplate = $license->template;

        if (!$newTemplate->isHigherTierThan($currentTemplate)) {
            throw new \RuntimeException('Can only upgrade to higher tier');
        }

        $upgraded = $this->templates->upgradeLicense($license, $newTemplate);

        if ($newTemplate->base_configuration['validity_days'] ?? null === 365) {
            $upgraded->renew(now()->addYear());
        }

        event(new LicenseUpgraded($upgraded, $currentTemplate, $newTemplate));

        return $upgraded;
    }
}
```

### 4. Middleware for Feature Checking

```php
// app/Http/Middleware/RequiresFeature.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequiresFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $license = $request->user()->organization->license;
        
        if (!$license || !$license->isUsable()) {
            return redirect()->route('billing.expired');
        }
        
        if (!$license->hasFeature($feature)) {
            return redirect()
                ->route('billing.upgrade')
                ->with('error', "This feature requires an upgrade to access.");
        }
        
        return $next($request);
    }
}

// Usage in routes
Route::middleware(['auth', 'requires-feature:advanced_analytics'])
    ->group(function () {
        Route::get('/analytics/advanced', [AnalyticsController::class, 'advanced']);
    });
```

### 5. Usage Quota Enforcement

```php
// app/Services/QuotaService.php
namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

class QuotaService
{
    public function checkApiQuota(Organization $org): bool
    {
        $license = $org->license;
        
        if (!$license || !$license->isUsable()) {
            return false;
        }
        
        $limit = $license->getEntitlement('api_calls_per_day');
        
        // Unlimited
        if ($limit === -1) {
            return true;
        }
        
        $key = "api_quota:{$org->id}:" . now()->format('Y-m-d');
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::expire($key, 86400); // Expire at end of day
        }
        
        return $current <= $limit;
    }
    
    public function getRemainingQuota(Organization $org): array
    {
        $license = $org->license;
        $limit = $license->getEntitlement('api_calls_per_day');
        
        if ($limit === -1) {
            return ['unlimited' => true];
        }
        
        $key = "api_quota:{$org->id}:" . now()->format('Y-m-d');
        $used = Cache::get($key, 0);
        
        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'resets_at' => now()->endOfDay(),
        ];
    }
}
```

---

## Desktop Software with Offline Verification

Complete implementation for desktop software with offline license verification.

### 1. License Activation Controller

```php
// app/Http/Controllers/Api/DesktopActivationController.php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Services\UsageRegistrarService;
use LucaLongo\Licensing\Services\PasetoTokenService;

class DesktopActivationController extends Controller
{
    public function activate(
        Request $request,
        UsageRegistrarService $registrar,
        PasetoTokenService $tokenService
    ) {
        $validated = $request->validate([
            'activation_key' => 'required|string',
            'device_fingerprint' => 'required|string',
            'device_info' => 'required|array',
        ]);
        
        // Find and verify license
        $license = License::findByKey($validated['activation_key']);
        
        if (!$license) {
            return response()->json([
                'error' => 'Invalid activation key',
            ], 404);
        }
        
        if (!$license->verifyKey($validated['activation_key'])) {
            return response()->json([
                'error' => 'Invalid activation key',
            ], 401);
        }
        
        // Activate if pending
        if ($license->status === LicenseStatus::Pending) {
            $license->activate();
        }
        
        // Check if license is usable
        if (!$license->isUsable()) {
            return response()->json([
                'error' => 'License is not active',
                'status' => $license->status->value,
            ], 403);
        }
        
        try {
            // Register device
            $usage = $registrar->register(
                $license,
                $validated['device_fingerprint'],
                [
                    'name' => $validated['device_info']['computer_name'] ?? 'Unknown',
                    'client_type' => 'desktop',
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'meta' => $validated['device_info'],
                ]
            );
            
            // Generate offline token
            $token = $tokenService->issue($license, $usage, [
                'ttl_days' => 30,
                'force_online_after' => 90,
                'include_entitlements' => true,
            ]);
            
            // Get public key bundle for offline verification
            $publicBundle = $this->getPublicKeyBundle();
            
            return response()->json([
                'success' => true,
                'license' => [
                    'id' => $license->uid,
                    'status' => $license->status->value,
                    'expires_at' => $license->expires_at,
                    'features' => $license->getFeatures(),
                    'entitlements' => $license->getEntitlements(),
                ],
                'token' => $token,
                'public_key_bundle' => $publicBundle,
                'refresh_before' => now()->addDays(25),
            ]);
            
        } catch (UsageLimitReachedException $e) {
            return response()->json([
                'error' => 'Device limit reached',
                'max_devices' => $license->max_usages,
                'active_devices' => $license->activeUsages()->count(),
            ], 403);
        }
    }
    
    public function refresh(Request $request, PasetoTokenService $tokenService)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'device_fingerprint' => 'required|string',
        ]);
        
        try {
            // Verify current token
            $claims = $tokenService->verify($validated['token']);
            
            // Load license and usage
            $license = License::findByUid($claims['license_id']);
            $usage = $license->usages()
                ->where('usage_fingerprint', $validated['device_fingerprint'])
                ->first();
            
            if (!$usage || !$usage->isActive()) {
                return response()->json([
                    'error' => 'Device not registered',
                ], 403);
            }
            
            // Update heartbeat
            $usage->heartbeat();
            
            // Issue new token
            $newToken = $tokenService->refresh($validated['token'], [
                'ttl_days' => 30,
            ]);
            
            return response()->json([
                'success' => true,
                'token' => $newToken,
                'refresh_before' => now()->addDays(25),
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Token refresh failed',
                'message' => $e->getMessage(),
            ], 401);
        }
    }
    
    private function getPublicKeyBundle(): array
    {
        return Cache::remember('public_key_bundle', 3600, function () {
            $ca = app(CertificateAuthorityService::class);
            $signingKey = LicensingKey::findActiveSigning();
            
            return [
                'root_public_key' => $ca->getRootPublicKey(),
                'signing_keys' => [
                    [
                        'kid' => $signingKey->kid,
                        'public_key' => $signingKey->public_key,
                        'certificate' => $signingKey->certificate,
                        'valid_from' => $signingKey->valid_from,
                        'valid_until' => $signingKey->valid_until,
                    ],
                ],
                'issued_at' => now(),
            ];
        });
    }
}
```

### 2. Desktop Client (Python Example)

```python
# desktop_client/license_manager.py
import json
import hashlib
import platform
import uuid
from datetime import datetime, timedelta
from pathlib import Path
import requests
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ed25519
import paseto

class DesktopLicenseManager:
    def __init__(self, server_url: str):
        self.server_url = server_url
        self.storage_path = self._get_storage_path()
        self.license_data = None
        self.token = None
        self.public_keys = None
        
    def _get_storage_path(self) -> Path:
        """Get platform-specific secure storage path"""
        if platform.system() == "Windows":
            base = Path.home() / "AppData" / "Local"
        elif platform.system() == "Darwin":  # macOS
            base = Path.home() / "Library" / "Application Support"
        else:  # Linux
            base = Path.home() / ".config"
        
        path = base / "MyApp" / "licensing"
        path.mkdir(parents=True, exist_ok=True)
        return path
    
    def _generate_fingerprint(self) -> str:
        """Generate unique device fingerprint"""
        components = {
            'machine_id': uuid.getnode(),  # MAC address
            'hostname': platform.node(),
            'platform': platform.platform(),
            'processor': platform.processor(),
        }
        
        # Create stable hash
        fingerprint_data = json.dumps(components, sort_keys=True)
        return hashlib.sha256(fingerprint_data.encode()).hexdigest()
    
    def _get_device_info(self) -> dict:
        """Collect device information"""
        return {
            'computer_name': platform.node(),
            'os': platform.system(),
            'os_version': platform.version(),
            'processor': platform.processor(),
            'python_version': platform.python_version(),
        }
    
    def activate(self, activation_key: str) -> bool:
        """Activate license with server"""
        try:
            response = requests.post(
                f"{self.server_url}/api/licensing/v1/activate",
                json={
                    'activation_key': activation_key,
                    'device_fingerprint': self._generate_fingerprint(),
                    'device_info': self._get_device_info(),
                },
                timeout=30
            )
            
            if response.status_code == 200:
                data = response.json()
                
                # Save license data
                self.license_data = data['license']
                self.token = data['token']
                self.public_keys = data['public_key_bundle']
                
                # Persist to secure storage
                self._save_license_data()
                
                print(f"License activated successfully!")
                print(f"Expires: {data['license']['expires_at']}")
                return True
            else:
                error = response.json().get('error', 'Unknown error')
                print(f"Activation failed: {error}")
                return False
                
        except requests.RequestException as e:
            print(f"Network error during activation: {e}")
            return False
    
    def validate(self) -> bool:
        """Validate license (offline first, then online)"""
        # Try offline validation first
        if self._validate_offline():
            return True
        
        # If offline validation fails, try online
        return self._validate_online()
    
    def _validate_offline(self) -> bool:
        """Validate license using stored token"""
        if not self.token or not self.public_keys:
            self._load_license_data()
        
        if not self.token:
            return False
        
        try:
            # Verify PASETO token
            verifier = paseto.PasetoV4()
            
            # Get signing public key
            signing_key = self.public_keys['signing_keys'][0]
            public_key = ed25519.Ed25519PublicKey.from_public_bytes(
                bytes.fromhex(signing_key['public_key'])
            )
            
            # Verify token
            payload = verifier.verify(
                self.token,
                public_key,
                implicit_assertion=b''
            )
            
            claims = json.loads(payload)
            
            # Check expiration
            exp = datetime.fromisoformat(claims['exp'])
            if datetime.now() > exp:
                print("License token expired")
                return False
            
            # Check fingerprint
            if claims['usage_fingerprint'] != self._generate_fingerprint():
                print("Device fingerprint mismatch")
                return False
            
            # Check force online date
            if 'force_online_after' in claims:
                force_online = datetime.fromisoformat(claims['force_online_after'])
                if datetime.now() > force_online:
                    print("Online validation required")
                    return self._validate_online()
            
            print("License validated offline successfully")
            return True
            
        except Exception as e:
            print(f"Offline validation failed: {e}")
            return False
    
    def _validate_online(self) -> bool:
        """Validate and refresh license online"""
        if not self.token:
            return False
        
        try:
            response = requests.post(
                f"{self.server_url}/api/licensing/v1/refresh",
                json={
                    'token': self.token,
                    'device_fingerprint': self._generate_fingerprint(),
                },
                timeout=30
            )
            
            if response.status_code == 200:
                data = response.json()
                self.token = data['token']
                self._save_license_data()
                print("License validated online and refreshed")
                return True
            else:
                print("Online validation failed")
                return False
                
        except requests.RequestException:
            print("Cannot reach license server")
            return False
    
    def _save_license_data(self):
        """Save license data to secure storage"""
        data = {
            'license': self.license_data,
            'token': self.token,
            'public_keys': self.public_keys,
            'saved_at': datetime.now().isoformat(),
        }
        
        # In production, encrypt this data
        license_file = self.storage_path / "license.json"
        with open(license_file, 'w') as f:
            json.dump(data, f, indent=2)
        
        # Set file permissions (Unix-like systems)
        if platform.system() != "Windows":
            import os
            os.chmod(license_file, 0o600)
    
    def _load_license_data(self):
        """Load license data from storage"""
        license_file = self.storage_path / "license.json"
        
        if license_file.exists():
            try:
                with open(license_file, 'r') as f:
                    data = json.load(f)
                
                self.license_data = data.get('license')
                self.token = data.get('token')
                self.public_keys = data.get('public_keys')
                
            except Exception as e:
                print(f"Failed to load license data: {e}")
    
    def has_feature(self, feature: str) -> bool:
        """Check if license has a specific feature"""
        if not self.license_data:
            self._load_license_data()
        
        if self.license_data and 'features' in self.license_data:
            return self.license_data['features'].get(feature, False)
        
        return False
    
    def get_entitlement(self, key: str, default=None):
        """Get entitlement value"""
        if not self.license_data:
            self._load_license_data()
        
        if self.license_data and 'entitlements' in self.license_data:
            return self.license_data['entitlements'].get(key, default)
        
        return default

# Usage example
if __name__ == "__main__":
    manager = DesktopLicenseManager("https://api.myapp.com")
    
    # First time activation
    if not manager.validate():
        key = input("Enter activation key: ")
        if manager.activate(key):
            print("Activation successful!")
    
    # Regular validation
    if manager.validate():
        print("License is valid")
        
        # Check features
        if manager.has_feature('advanced_analytics'):
            print("Advanced analytics enabled")
        
        # Check entitlements
        api_limit = manager.get_entitlement('api_calls_per_day')
        print(f"API calls limit: {api_limit}")
    else:
        print("License validation failed")
```

---

## Mobile App with Device Limits

Implementation for mobile apps with device registration limits.

### 1. Device Management Service

```php
// app/Services/MobileDeviceService.php
namespace App\Services;

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Services\UsageRegistrarService;
use Illuminate\Support\Collection;

class MobileDeviceService
{
    public function __construct(
        private UsageRegistrarService $registrar
    ) {}
    
    public function registerDevice(
        License $license,
        string $deviceId,
        array $deviceInfo
    ): LicenseUsage {
        // Check if device already registered
        $existing = $license->usages()
            ->where('usage_fingerprint', $deviceId)
            ->first();
        
        if ($existing) {
            if ($existing->isActive()) {
                // Update heartbeat
                $existing->heartbeat();
                return $existing;
            } else {
                // Reactivate if was revoked
                $existing->update(['status' => UsageStatus::Active]);
                return $existing;
            }
        }
        
        // Check device limit
        if (!$license->hasAvailableSeats()) {
            // Get least recently used device
            $lru = $license->activeUsages()
                ->orderBy('last_seen_at', 'asc')
                ->first();
            
            if ($license->getOverLimitPolicy() === OverLimitPolicy::AutoReplaceOldest) {
                // Auto-revoke oldest device
                $this->registrar->revoke($lru, 'Auto-replaced by new device');
            } else {
                throw new DeviceLimitException(
                    "Device limit reached. Please remove a device first.",
                    $license->max_usages,
                    $this->getDeviceList($license)
                );
            }
        }
        
        // Register new device
        return $this->registrar->register(
            $license,
            $deviceId,
            [
                'name' => $deviceInfo['device_name'] ?? 'Mobile Device',
                'client_type' => 'mobile',
                'meta' => [
                    'platform' => $deviceInfo['platform'], // ios/android
                    'os_version' => $deviceInfo['os_version'],
                    'app_version' => $deviceInfo['app_version'],
                    'model' => $deviceInfo['model'],
                ],
            ]
        );
    }
    
    public function getDeviceList(License $license): Collection
    {
        return $license->activeUsages()
            ->get()
            ->map(function ($usage) {
                return [
                    'id' => $usage->id,
                    'name' => $usage->name,
                    'platform' => $usage->meta['platform'] ?? 'unknown',
                    'last_seen' => $usage->last_seen_at,
                    'registered' => $usage->registered_at,
                    'is_current' => request()->header('X-Device-ID') === $usage->usage_fingerprint,
                ];
            });
    }
    
    public function removeDevice(License $license, string $usageId): bool
    {
        $usage = $license->usages()->find($usageId);
        
        if (!$usage) {
            return false;
        }
        
        $this->registrar->revoke($usage, 'Removed by user');
        
        return true;
    }
}
```

### 2. Mobile API Controller

```php
// app/Http/Controllers/Api/MobileController.php
namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Services\MobileDeviceService;
use LucaLongo\Licensing\Models\License;

class MobileController extends Controller
{
    public function __construct(
        private MobileDeviceService $deviceService
    ) {}
    
    public function activate(Request $request)
    {
        $validated = $request->validate([
            'activation_key' => 'required|string',
            'device_id' => 'required|string',
            'device_info' => 'required|array',
        ]);
        
        $license = License::findByKey($validated['activation_key']);
        
        if (!$license || !$license->verifyKey($validated['activation_key'])) {
            return response()->json([
                'error' => 'Invalid activation key',
            ], 401);
        }
        
        try {
            $usage = $this->deviceService->registerDevice(
                $license,
                $validated['device_id'],
                $validated['device_info']
            );
            
            return response()->json([
                'success' => true,
                'license' => [
                    'id' => $license->uid,
                    'expires_at' => $license->expires_at,
                    'features' => $license->getFeatures(),
                ],
                'device' => [
                    'id' => $usage->id,
                    'name' => $usage->name,
                ],
                'devices_remaining' => $license->getAvailableSeats(),
            ]);
            
        } catch (DeviceLimitException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'device_limit' => $e->limit,
                'registered_devices' => $e->devices,
            ], 403);
        }
    }
    
    public function listDevices(Request $request)
    {
        $license = $request->user()->license;
        
        return response()->json([
            'devices' => $this->deviceService->getDeviceList($license),
            'limit' => $license->max_usages,
            'remaining' => $license->getAvailableSeats(),
        ]);
    }
    
    public function removeDevice(Request $request, string $deviceId)
    {
        $license = $request->user()->license;
        
        if ($this->deviceService->removeDevice($license, $deviceId)) {
            return response()->json([
                'success' => true,
                'message' => 'Device removed successfully',
            ]);
        }
        
        return response()->json([
            'error' => 'Device not found',
        ], 404);
    }
}
```

### 3. iOS Client (Swift)

```swift
// LicenseManager.swift
import Foundation
import CryptoKit
import UIKit

class LicenseManager {
    static let shared = LicenseManager()
    
    private let serverURL = "https://api.myapp.com"
    private let keychain = KeychainService()
    
    private var license: License?
    private var deviceId: String {
        return UIDevice.current.identifierForVendor?.uuidString ?? UUID().uuidString
    }
    
    struct License: Codable {
        let id: String
        let expiresAt: Date
        let features: [String: Bool]
    }
    
    // MARK: - Activation
    
    func activate(with key: String, completion: @escaping (Result<License, Error>) -> Void) {
        let deviceInfo = [
            "device_name": UIDevice.current.name,
            "platform": "ios",
            "os_version": UIDevice.current.systemVersion,
            "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "",
            "model": UIDevice.current.model
        ]
        
        let payload = [
            "activation_key": key,
            "device_id": deviceId,
            "device_info": deviceInfo
        ] as [String: Any]
        
        APIClient.shared.post("/api/licensing/v1/mobile/activate", body: payload) { result in
            switch result {
            case .success(let data):
                if let license = try? JSONDecoder().decode(License.self, from: data) {
                    self.license = license
                    self.saveLicense(license)
                    completion(.success(license))
                }
            case .failure(let error):
                completion(.failure(error))
            }
        }
    }
    
    // MARK: - Validation
    
    func validateLicense(completion: @escaping (Bool) -> Void) {
        // Check cached license first
        if let license = loadLicense() {
            if license.expiresAt > Date() {
                self.license = license
                completion(true)
                return
            }
        }
        
        // Validate with server
        APIClient.shared.post("/api/licensing/v1/mobile/validate", 
                              headers: ["X-Device-ID": deviceId]) { result in
            switch result {
            case .success(_):
                completion(true)
            case .failure(_):
                completion(false)
            }
        }
    }
    
    // MARK: - Feature Checking
    
    func hasFeature(_ feature: String) -> Bool {
        return license?.features[feature] ?? false
    }
    
    func requireFeature(_ feature: String, in viewController: UIViewController) -> Bool {
        if hasFeature(feature) {
            return true
        }
        
        // Show upgrade prompt
        let alert = UIAlertController(
            title: "Premium Feature",
            message: "This feature requires a premium license.",
            preferredStyle: .alert
        )
        
        alert.addAction(UIAlertAction(title: "Upgrade", style: .default) { _ in
            self.showUpgradeScreen(in: viewController)
        })
        
        alert.addAction(UIAlertAction(title: "Cancel", style: .cancel))
        
        viewController.present(alert, animated: true)
        return false
    }
    
    // MARK: - Device Management
    
    func getRegisteredDevices(completion: @escaping ([Device]) -> Void) {
        APIClient.shared.get("/api/licensing/v1/mobile/devices") { result in
            switch result {
            case .success(let data):
                if let devices = try? JSONDecoder().decode([Device].self, from: data) {
                    completion(devices)
                }
            case .failure(_):
                completion([])
            }
        }
    }
    
    func removeDevice(_ deviceId: String, completion: @escaping (Bool) -> Void) {
        APIClient.shared.delete("/api/licensing/v1/mobile/devices/\(deviceId)") { result in
            switch result {
            case .success(_):
                completion(true)
            case .failure(_):
                completion(false)
            }
        }
    }
    
    // MARK: - Storage
    
    private func saveLicense(_ license: License) {
        if let data = try? JSONEncoder().encode(license) {
            keychain.save(data, for: "license")
        }
    }
    
    private func loadLicense() -> License? {
        guard let data = keychain.load(for: "license"),
              let license = try? JSONDecoder().decode(License.self, from: data) else {
            return nil
        }
        return license
    }
}
```

---

## Trial to Paid Conversion Flow

Complete implementation of trial license with conversion tracking.

### 1. Trial Registration

```php
// app/Http/Controllers/TrialController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LucaLongo\Licensing\Services\TrialService;
use LucaLongo\Licensing\Models\License;
use App\Models\User;

class TrialController extends Controller
{
    public function __construct(
        private TrialService $trialService
    ) {}
    
    public function startTrial(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:users',
            'name' => 'required|string',
            'company' => 'nullable|string',
        ]);
        
        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'],
        ]);
        
        // Create trial license
        $license = License::create([
            'key_hash' => License::hashKey(Str::random(32)),
            'licensable_type' => User::class,
            'licensable_id' => $user->id,
            'status' => LicenseStatus::Active,
            'expires_at' => now()->addDays(14),
            'max_usages' => 1,
            'meta' => [
                'is_trial' => true,
                'trial_type' => 'standard',
            ],
        ]);
        
        // Generate device fingerprint
        $fingerprint = hash('sha256', $request->ip() . $request->userAgent());
        
        // Start trial with limitations
        $trial = $this->trialService->start(
            $license,
            $fingerprint,
            14, // days
            [
                'max_projects' => 3,
                'max_users' => 1,
                'watermark' => true,
                'export_disabled' => true,
                'api_access' => false,
            ]
        );
        
        // Send welcome email
        Mail::to($user->email)->send(new TrialStartedMail($user, $trial));
        
        // Track trial start
        event(new TrialStartedEvent($user, $trial));
        
        return redirect()->route('trial.dashboard')
            ->with('success', 'Your 14-day trial has started!');
    }
    
    public function extendTrial(Request $request)
    {
        $user = $request->user();
        $trial = $user->license->trials()->active()->first();
        
        if (!$trial || !$trial->canExtend()) {
            return back()->with('error', 'Trial cannot be extended');
        }
        
        // One-time 7-day extension
        if ($trial->extension_count >= 1) {
            return back()->with('error', 'Trial has already been extended');
        }
        
        $validated = $request->validate([
            'reason' => 'required|string|min:20',
        ]);
        
        $trial = $this->trialService->extend(
            $trial,
            7,
            $validated['reason']
        );
        
        // Track extension
        event(new TrialExtendedEvent($user, $trial));
        
        return back()->with('success', 'Trial extended for 7 more days!');
    }
    
    public function convertTrial(Request $request)
    {
        $user = $request->user();
        $trial = $user->license->trials()->active()->first();
        
        if (!$trial || !$trial->canConvert()) {
            return back()->with('error', 'Trial cannot be converted');
        }
        
        $validated = $request->validate([
            'plan' => 'required|in:basic,pro,enterprise',
            'payment_method' => 'required|string',
        ]);
        
        DB::transaction(function () use ($user, $trial, $validated) {
            // Process payment (simplified)
            $payment = $this->processPayment(
                $user,
                $validated['plan'],
                $validated['payment_method']
            );
            
            // Convert trial to full license
            $fullLicense = $this->trialService->convert(
                $trial,
                'purchase',
                $payment->amount
            );
            
            // Update license with new template
            $template = LicenseTemplate::findBySlug($validated['plan']);
            $fullLicense->update([
                'template_id' => $template->id,
                'expires_at' => now()->addYear(),
                'max_usages' => $template->base_configuration['max_usages'],
                'meta' => array_merge($fullLicense->meta->toArray(), [
                    'is_trial' => false,
                    'converted_from_trial' => true,
                    'conversion_date' => now(),
                    'payment_id' => $payment->id,
                ]),
            ]);
            
            // Remove trial limitations
            $fullLicense->meta = collect($fullLicense->meta)
                ->except(['limitations'])
                ->toArray();
            $fullLicense->save();
            
            // Track conversion
            event(new TrialConvertedEvent($user, $trial, $fullLicense, $payment));
        });
        
        return redirect()->route('dashboard')
            ->with('success', 'Welcome to the full version!');
    }
}
```

### 2. Trial Monitoring Dashboard

```php
// app/Http/Controllers/Admin/TrialMonitoringController.php
namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use LucaLongo\Licensing\Models\LicenseTrial;
use Illuminate\Support\Facades\DB;

class TrialMonitoringController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'active_trials' => LicenseTrial::where('status', TrialStatus::Active)->count(),
            'conversions_today' => LicenseTrial::where('status', TrialStatus::Converted)
                ->whereDate('converted_at', today())
                ->count(),
            'conversion_rate' => $this->calculateConversionRate(),
            'average_trial_days' => $this->calculateAverageTrialDays(),
        ];
        
        $recentTrials = LicenseTrial::with('license.licensable')
            ->latest()
            ->take(10)
            ->get();
        
        $conversionFunnel = $this->getConversionFunnel();
        
        return view('admin.trials.dashboard', compact(
            'stats',
            'recentTrials',
            'conversionFunnel'
        ));
    }
    
    private function calculateConversionRate(): float
    {
        $total = LicenseTrial::whereIn('status', [
            TrialStatus::Converted,
            TrialStatus::Expired,
            TrialStatus::Cancelled,
        ])->count();
        
        if ($total === 0) {
            return 0;
        }
        
        $converted = LicenseTrial::where('status', TrialStatus::Converted)->count();
        
        return round(($converted / $total) * 100, 2);
    }
    
    private function calculateAverageTrialDays(): float
    {
        return LicenseTrial::where('status', TrialStatus::Converted)
            ->selectRaw('AVG(DATEDIFF(converted_at, started_at)) as avg_days')
            ->value('avg_days') ?? 0;
    }
    
    private function getConversionFunnel(): array
    {
        return [
            'started' => LicenseTrial::count(),
            'activated' => LicenseTrial::whereNotNull('started_at')->count(),
            'engaged' => $this->getEngagedTrialsCount(),
            'extended' => LicenseTrial::where('extension_count', '>', 0)->count(),
            'converted' => LicenseTrial::where('status', TrialStatus::Converted)->count(),
        ];
    }
    
    private function getEngagedTrialsCount(): int
    {
        // Define engagement as users who used the trial for at least 3 days
        return LicenseTrial::whereRaw('DATEDIFF(COALESCE(converted_at, expires_at, NOW()), started_at) >= 3')
            ->count();
    }
}
```

---

## Next Steps

- Review [API Reference](../api/models.md) for detailed method documentation
- Check [Security Guide](../advanced/security.md) for best practices
- See [Client Library Architecture](../client-libraries/architecture.md) for building custom clients
- Explore [Integration Examples](integrations.md) for third-party services
