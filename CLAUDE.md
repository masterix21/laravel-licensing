
# CLAUDE.md

## Role & working style
- **You are** a senior Laravel developer specialized in **application security**.
- **Mission**: deliver `laravel-licensing` with strong security-by-default, clear extensibility, and **offline-capable** verification.
- **Style**: concise, pragmatic, document decisions.
- **Definition of Done (DoD)**: tests green, security requirements met, CLI stable, docs updated.

---

## Project overview
Licensing package for Laravel with:
- **Polymorphic assignment** (`License → licensable`) to bind a license to any application model.
- **Activation keys**, **expirations/renewals**, and seat control via **LicenseUsage** (usage = one seat).
- **Offline verification** using public-key–signed tokens and a **two-level key hierarchy** (**root → signing**) for safe rotation & compromise handling.
- **Out of scope for v1**: multi-tenant isolation, billing/invoicing, advanced entitlement management (hook points only).

---

## Architecture (high level)
- **Domain models** (overridable via config/contracts): `License`, `LicenseUsage`, `LicenseRenewal`.
- **Policies**: over-limit handling, grace periods, inactivity auto-revocation.
- **Crypto**: Ed25519 (default) for signatures; root CA issues short-lived signing keys; tokens carry `kid` and a chain to root.
- **Interfaces (contracts)** to allow project-level replacement: KeyStore, CertificateAuthority, TokenIssuer/Verifier, UsageRegistrar, FingerprintResolver, Notifier.
- **CLI** for key lifecycle (make/issue/rotate/revoke/export) and offline token issuance.
- **API (optional)** for validate/refresh/jwks/usages.
- **Jobs/Scheduler** for state transitions and notifications.

---

## Core entities (conceptual)
### License
- Fields: `id(ULID)`, `key_hash`, `status[pending|active|grace|expired|suspended|cancelled]`, `activated_at`, `expires_at`, `max_usages`, `meta(json)`.
- Relations: `licensable (morphTo)`, `usages (hasMany)`, `renewals (hasMany)`.
- Indexes: `expires_at`, `status`, `key_hash (unique)`, `licensable_type+licensable_id`.

### LicenseUsage
- Meaning: one **seat consumption** (device/VM/service/user/session).
- Fields: `id`, `license_id`, `usage_fingerprint`, `status[active|revoked]`, `registered_at`, `last_seen_at`, `revoked_at`, `client_type`, `name`, `ip?`, `user_agent?`, `meta(json)`.
- Uniqueness (default): **per-license** → unique(`license_id`,`usage_fingerprint`). Configurable to **global**.
- Indexes: unique composite above, plus `revoked_at`, `last_seen_at`.

### LicenseRenewal
- Fields: `id`, `license_id`, `period_start`, `period_end`, `amount_cents?`, `currency?`, `notes?`.

---

## States & lifecycle
- States: `pending → active → grace → expired`; side paths: `suspended`, `cancelled`.
- Events: `LicenseActivated`, `LicenseExpiringSoon`, `LicenseExpired`, `LicenseRenewed`, `UsageRegistered`, `UsageRevoked`, `UsageLimitReached`.
- Scheduler (daily): time-based transitions, emit notifications/webhooks.

---

## Configuration (defaults are secure & overridable)
`config/licensing.php` keys:
- `models`: `license`, `license_usage`, `license_renewal` (class names to override).
- `morph_map`: aliases for `licensable` types (hide app class names).
- `policies`:
  - `over_limit`: `reject` (default) | `auto_replace_oldest`.
  - `grace_days`: `14`.
  - `usage_inactivity_auto_revoke_days`: `null` (off) or integer.
  - `unique_usage_scope`: `license` (default) | `global`.
- `offline_token`:
  - `enabled`: `true`.
  - `format`: `paseto` (default) | `jws`.
  - `ttl_days`: `7`.
  - `force_online_after_days`: `14`.
  - `clock_skew_seconds`: `60`.
- `crypto`:
  - `algorithm`: `ed25519` (default) | `ES256`.
  - `keystore.driver`: `files` | `database` | `custom`.
  - `keystore.path`: `storage/app/licensing/keys`.
  - `keystore.passphrase_env`: `LICENSING_KEY_PASSPHRASE`.
- `publishing`:
  - `jwks_url`: nullable (for JWS clients).
  - `public_bundle_path`: path to bundle with root public and chain (PEM/JSON).
- `rate_limit`:
  - `validate_per_minute`: `60`.
  - `token_per_minute`: `20`.
  - `register_per_minute`: `30`.
- `notifications`: toggle per event; choose mail/queue/webhook via Notifier contract.
- `audit`:
  - `enabled`: `true`.
  - `store`: `database` | `file`.

All timestamps stored in **UTC**.

---

## Security requirements
- Activation keys: store **salted hash** only; constant-time comparisons; human-readable format allowed (chunks+checksum).
- Offline tokens: signed only; client holds **public** material; no private keys client-side.
- Two-level hierarchy:
  - **Root (pub/priv)** = trust anchor, **never** signs tokens; used to sign **signing-key certificates**.
  - **Signing key (pub/priv)** = signs offline tokens; short-lived; includes `kid`; distributed with a **chain** up to root.
- Rotation & compromise:
  - New signing key issued; old marked **revoked**; tokens switch to new `kid`.
  - Clients validating with **root public** continue to work offline (chain validates).
- Rate limit online endpoints by `license_key` and `usage_fingerprint`.
- Concurrency: enforce `max_usages` via **pessimistic locks** during registration.
- Privacy: `ip`/`user_agent` optional; retention window configurable; avoid PII in fingerprints.
- Clock skew tolerance ±60s in token validation.
- Audit trail: append-only records for key lifecycle, license state changes, usage events.

---

## Fingerprint & usage policy
- `usage_fingerprint` must be **stable**, **repeatable**, and **non-PII** (e.g., hashed tuple of hardware/app traits).
- Default uniqueness: **per license**; allow **global** uniqueness via config.
- Over-limit default: **reject**; optional **auto_replace_oldest** (revokes the least recent active usage).
- Heartbeat updates `last_seen_at`; optional auto-revoke after prolonged inactivity.

---

## Offline verification (design)
- Token format: **PASETO v4.public** (default) or **JWS**.
- Required claims: `license_id`, `license_key_hash`, `usage_fingerprint`, `status`, `exp`, `nbf`, `iat`, `max_usages`, optional `grace_until`, `entitlements`, `licensable_ref`, `serial`.
- Headers/metadata: `kid`, `chain` (certificate of signing key signed by root), token `version`.
- TTL: default 7 days; include `force_online_after` in payload to enforce periodic online checks.
- Revocation limits offline guarantees; mitigation via short TTL and forced online windows.

---

## CLI (semantics)
- `licensing:keys:make-root`
  - Generates **root** keypair; stores private encrypted; outputs public bundle path.
- `licensing:keys:issue-signing --kid K1 [--nbf ISO --exp ISO]`
  - Generates signing keypair; issues certificate signed by root; marks as **active**.
- `licensing:keys:rotate --reason <routine|compromised>`
  - Revokes current signing key (set `revoked_at`); issues new signing key; updates published set.
- `licensing:keys:revoke <KID> [--at ISO]`
  - Marks signing key as revoked (immediate or retroactive).
- `licensing:keys:list`
  - Shows root and signing keys with `status`, `kid`, validity, revocation info.
- `licensing:keys:export --format <jwks|pem|json> [--include-chain]`
  - Exports public materials for clients (JWKS for JWS; bundle for PASETO).
- `licensing:offline:issue --license <id|key> --fingerprint <fp> --ttl 7d`
  - Issues an offline token bound to the usage fingerprint.
- Return codes: `0` success, `1` invalid args, `2` not found, `3` revoked/compromised, `4` I/O or crypto error.

---

## API surface (optional, versioned `/api/licensing/v1`)
- `POST /validate` → online license check; returns status, remaining days, usage policy.
- `POST /token` → issues/refreshes offline token (requires valid license & usage).
- `GET /jwks.json` → public keys (for JWS mode).
- `POST /licenses/{id}/usages:register|heartbeat|revoke|replace` → manage usages.
- Errors: JSON with `code`, `type`, `message`, `hint`, `retry_after?`.

---

## Scheduler & jobs
- Nightly `check-expirations` → transitions `active→grace/expired`, emits `ExpiringSoon`/`Expired`.
- Optional `cleanup-inactive-usages`.
- Optional `notify-expiring` (N, N/2, N/4 days before).

---

## Observability & audit
- Metrics: active licenses, usages in use, over-limit rejections, token refresh rate, clock drift (iat vs server).
- Audit log entries:
  - License: create/activate/extend/renew/suspend/cancel/expire.
  - Usage: register/revoke/replace/limit-reached.
  - Keys: make-root/issue-signing/rotate/revoke/export.
- Audit store must be append-only; consider hash-chained entries for tamper-evidence.

---

## Testing checklist
- Usage registration concurrency (lock correctness) and over-limit behavior.
- Token issue/verify happy path; clock skew; expired/nbf failures; forced-online window.
- Key rotation: old token rejection post-revocation; new token acceptance with chain.
- Compromise flow: rotate with `reason=compromised`; ensure published set excludes revoked.
- State transitions by time; grace handling; renewal extending `expires_at`.
- Rate limiting & error contracts.

---

## Performance & scalability
- Indexes on `expires_at`, `status`, composite on (`license_id`,`usage_fingerprint`).
- Pessimistic lock only in the critical section of usage registration; keep transactions short.
- Token verification is pure CPU (ed25519); design for offline fast path (no I/O).
- Provide configuration for cache headers on JWKS/bundles.

---

## Backward compatibility & versioning
- Semver: breaking changes only in majors.
- Public API endpoints have `/v1` prefix.
- Config keys are namespaced; deprecations documented with migration notes.

---

## Folder structure (package)
- `config/licensing.php` (publishable).
- `src/` (contracts, services, policies, jobs, console).
- `src/Models/` (bound via config, no hard-coded class names).
- `database/migrations/` (publishable).
- `routes/api.php` (optional endpoints, guarded by config).
- `docs/` (usage, security model, CLI reference).
- `stubs/` (policy/event/listener stubs).
- `tests/` (unit/feature/security).

---

## Deliverables (v1)
- Overridable models, migrations, and policies per specs.
- CLI for key lifecycle and offline token issuance.
- Optional API endpoints; middleware & rate limiters.
- Documentation: security model, rotation procedure, offline usage, config reference.
- Test suite covering security and lifecycle.

---

## Glossary
- **LicenseUsage**: one consumed seat (device/VM/service/user).
- **Fingerprint**: stable, non-PII identifier bound to a usage.
- **Root key**: trust anchor; signs signing-key certificates only.
- **Signing key**: signs offline tokens; short-lived; rotatable via `kid`.
- **Chain**: certificate data linking signing key to root.


