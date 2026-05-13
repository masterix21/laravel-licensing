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
