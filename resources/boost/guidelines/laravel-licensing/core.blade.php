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
# laravel-licensing — Licenses

## Key format
`PREFIX-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX` — 8 hex chars per segment, 128-bit entropy generated with `random_bytes()`.

## Storage
- `key_hash` = HMAC-SHA256 of the plaintext key.
- Plaintext is shown to the operator **once** at creation. Never persisted.
- Lookups use constant-time comparison.

## Create and activate
```php
use LucaLongo\Licensing\Models\License;

// createWithKey() generates a key, stores only the hash, and sets
// $license->license_key to the plaintext for one-time display.
$license = License::createWithKey([
    'licensable_type' => 'app-user',          // morph map alias
    'licensable_id'   => $user->id,
    'max_usages'      => 3,
    'expires_at'      => now()->addYear(),
    'meta'            => ['plan' => 'pro'],
]);

$plainKey = $license->license_key;            // plaintext available ONCE after createWithKey()
$license->activate();                         // pending → active
```

## Renew
```php
$license->renew(expiresAt: now()->addYear()); // extends expires_at, writes a LicenseRenewal row
```
Extends `expires_at` and writes a `LicenseRenewal` row.

## Transitions
Nightly scheduler `licensing:check-expirations` moves `active → grace → expired` and emits events.

## Rules
- DON'T compare keys with `==` — use the package's `findByKey()` resolver.
- DON'T mutate `status` directly; call domain methods (`activate`, `suspend`, `cancel`, `expire`).
- DO listen to `LicenseExpiringSoon` for in-app notifications.
# laravel-licensing — Usages (Seats)

One `LicenseUsage` row = one consumed seat (device/VM/service/user/session).

## Register
```php
use LucaLongo\Licensing\Contracts\UsageRegistrar;

$usage = app(UsageRegistrar::class)->register(
    license: $license,
    fingerprint: $fingerprint,                // stable, non-PII, max 255 chars
    metadata: [
        'client_type' => 'desktop',
        'name'        => "Luca's MacBook",
    ],
);
```
The service wraps registration in a pessimistic lock so `max_usages` cannot be exceeded.

## Fingerprint policy
- Stable across restarts, non-PII, `max:255`.
- Default uniqueness: per-license (unique on `license_id` + `usage_fingerprint`).
- Switch to global: `config('licensing.policies.unique_usage_scope') = 'global'`.

## Over-limit
`config('licensing.policies.over_limit')`:
- `reject` (default) — throws `UsageLimitReached`.
- `auto_replace_oldest` — revokes least-recently-active usage.

## Heartbeat
```php
// heartbeat() takes no arguments — it only updates last_seen_at.
$usage->heartbeat();

// To store client-supplied data, write to meta.client_data manually before saving:
$meta = $usage->meta?->toArray() ?? [];
$meta['client_data'] = ['app_version' => '2.1.0'];
$usage->update(['meta' => $meta]);
```
Client-supplied fields must be namespaced under `meta.client_data`. **Never** merge raw client input into `meta` root.

## Auto-revoke (optional)
`config('licensing.policies.usage_inactivity_auto_revoke_days') = 30` to revoke usages whose `last_seen_at` is older than N days.

## Rules
- DON'T derive fingerprints from PII (email, MAC, full UA).
- DON'T register usages outside the package service — pessimistic lock guarantees only hold there.
- DO call `revoke()` on logout/uninstall to free a seat.
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
# laravel-licensing — Trials

## Start a trial
```php
use LucaLongo\Licensing\Services\TrialService;

// TrialService::startTrial() requires a License to attach the trial to.
// Create or retrieve the license first, then start the trial.
$trial = app(TrialService::class)->startTrial(
    license: $license,
    fingerprint: $fingerprint,
    durationDays: 14,
);
```

## Fingerprint hashing
- Stored as **HMAC-SHA256** with `config('app.key')` as the key.
- Legacy SHA256 fallback for backward compatibility.
- Same fingerprint stable across trial → conversion to keep attribution.

## Conversion
```php
// convert() issues a License from the trial's existing license template,
// marks the trial as Converted, and fires TrialConverted.
$license = $trial->convert(trigger: 'checkout', value: 99.0);
```
The trial row is preserved (audit), `converted_at` is set.

## Rules
- DON'T reuse the same fingerprint across scopes unless you explicitly want shared trial limits.
- DO check `LicenseTrial::hasActiveTrialForFingerprint($fingerprint)` before offering a new trial.
- DON'T store raw fingerprints anywhere — only HMAC.
# laravel-licensing — Offline Tokens

## Format
PASETO v4.public (default) or JWS — `config('licensing.offline_token.format')`.

## Key hierarchy
- **Root key** = trust anchor. Signs signing-key certificates only. Never signs tokens.
- **Signing key** = short-lived, has `kid`, signs offline tokens.
- Clients ship with **root public** only. Chain validates signing key → root.

## Claims
`license_id`, `license_key_hash`, `usage_fingerprint`, `status`, `exp`, `nbf`, `iat`, `max_usages`. Optional: `grace_until`, `entitlements`, `licensable_ref`, `serial`.

## Headers / metadata
`kid`, `chain` (signing-key cert signed by root), token `version`.

## Time policy
- TTL default 7 days (`config('licensing.offline_token.ttl_days')`).
- `force_online_after_days = 14` — client must come back online after this window.
- Clock skew tolerance ±60s.

## Rotation
```bash
php artisan licensing:keys:rotate --reason routine
```
Old signing key gets `revoked_at`. New tokens use the new `kid`. Clients with root public continue to validate via chain — no client update needed.

## Compromise
```bash
php artisan licensing:keys:rotate --reason compromised
php artisan licensing:keys:export --format jwks --include-chain
```
Publish updated bundle / JWKS so clients reject revoked `kid`.

## Rules
- DON'T ship private keys to clients. Ever.
- DON'T rely solely on offline tokens for high-value seats — combine with `force_online_after_days`.
- DO use short TTLs (≤ 7d) to limit blast radius of revocation lag.
# laravel-licensing — CLI

## Key lifecycle
```bash
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing --kid signing-key-1 [--nbf <ISO> --exp <ISO>]
php artisan licensing:keys:rotate --reason <routine|compromised>
php artisan licensing:keys:revoke <KID> [--at <ISO>]
php artisan licensing:keys:list
php artisan licensing:keys:export --format <jwks|pem|json> [--include-chain]
```
Private keys stored encrypted with the passphrase from `LICENSING_KEY_PASSPHRASE`.

## Offline token issuance
```bash
php artisan licensing:offline:issue \
    --license <id|key> \
    --fingerprint <fp> \
    --ttl 7d
```

## Maintenance
```bash
php artisan licensing:check-expirations         # nightly: state transitions + events
php artisan licensing:cleanup-inactive-usages   # optional auto-revoke
php artisan licensing:notify-expiring           # N, N/2, N/4 days before expiry
```

## Return codes
- `0` success
- `1` invalid args
- `2` not found
- `3` revoked / compromised
- `4` I/O or crypto error

## Rules
- DO set `LICENSING_KEY_PASSPHRASE` before `make-root`. Without it, interactive prompt; in CI use `--no-interaction` with the env set.
- DON'T commit `storage/app/licensing/keys` to git.
- DO export updated public bundle after every rotation and ship to clients.
# laravel-licensing — API & Security

## Endpoints (prefix `/api/licensing/v1`)
| Method | Path                | Middleware                        |
|--------|---------------------|-----------------------------------|
| POST   | /activate           | throttle:licensing-register       |
| POST   | /deactivate         | throttle:licensing-register       |
| POST   | /refresh            | throttle:licensing-token          |
| POST   | /validate           | throttle:licensing-validate       |
| POST   | /heartbeat          | throttle:licensing-validate       |
| POST   | /licenses/show      | throttle:licensing-validate       |
| POST   | /token              | throttle:licensing-token          |
| GET    | /health             | —                                 |

Default limits: `validate_per_minute=60`, `register_per_minute=30`, `token_per_minute=20`.

## Error contract
```json
{ "code": "USAGE_LIMIT_REACHED", "message": "Seat limit reached for this license." }
```
Generic message on 500s. Business codes: `INVALID_KEY`, `USAGE_LIMIT_REACHED`, `LICENSE_EXPIRED`, `LICENSE_SUSPENDED`, `FINGERPRINT_MISMATCH`.

## Health
Returns `{ "status": "ok" | "error" }` per check only. **Never** leak `kid`, validity windows, or exception text.

## DO
- Validate `fingerprint` with `max:255` on every endpoint.
- Resolve services from the container so consumers can override via contracts.
- Use the morph map for `licensable_type`.
- `report()` internal exceptions; return generic 500 message.

## DON'T
- Echo internal exception messages to clients.
- Merge client-supplied data into `meta` root — use `meta.client_data`.
- Log PII (email, IP, full UA) into the audit trail by default.
- Bypass the pessimistic lock in usage registration.

## Audit
Append-only via `config('licensing.audit.store') = 'database' | 'file'`. Consider hash-chained entries for tamper-evidence.
