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
