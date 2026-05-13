# laravel-licensing — Scopes & Templates

## LicenseScope
Isolates licenses and signing keys per product/software.
```php
use LucaLongo\Licensing\Models\LicenseScope;

$scope = LicenseScope::create([
    'name' => 'My Desktop App',
    'slug' => 'desktop-app',
]);
```
Use a scope when shipping multiple products from one Laravel app — a compromise of one signing key does not affect the others.

## LicenseTemplate
Prebuilt license configuration bound to a scope. Uses `spatie/laravel-sluggable` v4 for slug generation.
```php
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTemplate;

$template = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name'             => 'Pro 1-year, 3 seats',
    'max_usages'       => 3,
    'duration_days'    => 365,
    'meta'             => ['plan' => 'pro'],
]);

$license = License::createFromTemplate($template, [
    'licensable_type' => 'app-user',
    'licensable_id'   => $user->id,
]);
```

## Rules
- DO create one scope per product or product line.
- DON'T reuse a scope across unrelated products — defeats the isolation purpose.
- DO issue licenses through templates when configuration is repetitive.
