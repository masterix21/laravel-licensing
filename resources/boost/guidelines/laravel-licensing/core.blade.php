# laravel-licensing — Core

## Requirements
- PHP 8.3–8.5
- Laravel 12 or 13
- `ext-openssl`, `ext-sodium`

## Install
```bash
composer require masterix21/laravel-licensing
php artisan vendor:publish --provider="LucaLongo\Licensing\LicensingServiceProvider"
php artisan migrate
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing --kid signing-key-1
```

## Entities
- `LucaLongo\Licensing\Models\License` — issued license, polymorphic `licensable` morphTo.
- `LucaLongo\Licensing\Models\LicenseUsage` — one consumed seat (device/VM/service/user/session).
- `LucaLongo\Licensing\Models\LicenseRenewal` — extension records.
- `LucaLongo\Licensing\Models\LicenseScope` — multi-product isolation.
- `LucaLongo\Licensing\Models\LicenseTemplate` — prebuilt configuration linked to a scope.
- `LucaLongo\Licensing\Models\LicenseTrial` — trial period tracking.

## Lifecycle states
`pending → active → grace → expired`. Side paths: `suspended`, `cancelled`. All timestamps UTC.

## Rules
- DO override models via `config('licensing.models.*')`, not subclass directly.
- DO register morph map in `config('licensing.morph_map')` to hide app class names from tokens.
- DO resolve services from container (`app(...)` or DI), never `new` them.
- DON'T edit published migrations after deploy — write follow-up migrations instead.
- DON'T store license keys in plaintext anywhere; the package stores `key_hash` only.

## Events
`LicenseActivated`, `LicenseExpiringSoon`, `LicenseExpired`, `LicenseRenewed`, `UsageRegistered`, `UsageRevoked`, `UsageLimitReached`.
