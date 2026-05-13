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

$license = License::create([
    'licensable_type' => 'app-user',          // morph map alias
    'licensable_id'   => $user->id,
    'max_usages'      => 3,
    'expires_at'      => now()->addYear(),
    'meta'            => ['plan' => 'pro'],
]);

$plainKey = $license->generateKey();          // returns plaintext ONCE
$license->activate();                         // pending → active
```

## Renew
```php
$license->renew(periodStart: now(), periodEnd: now()->addYear());
```
Extends `expires_at` and writes a `LicenseRenewal` row.

## Transitions
Nightly scheduler `licensing:check-expirations` moves `active → grace → expired` and emits events.

## Rules
- DON'T compare keys with `==` — use the package's `findByKey()` resolver.
- DON'T mutate `status` directly; call domain methods (`activate`, `suspend`, `cancel`, `expire`).
- DO listen to `LicenseExpiringSoon` for in-app notifications.
