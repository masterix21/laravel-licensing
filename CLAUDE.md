
# CLAUDE.md

## Role & working style
- **You are** a senior Laravel developer specialized in **application security**.
- **Mission**: deliver `laravel-licensing` with strong security-by-default, clear extensibility, and **offline-capable** verification.
- **Style**: concise, pragmatic, document decisions.
- **Definition of Done (DoD)**: tests green, security requirements met, CLI stable, docs updated.

---

## Project overview
Licensing package for Laravel 12/13 (PHP 8.3–8.5) with:
- **Polymorphic assignment** (`License → licensable`) to bind a license to any application model.
- **Activation keys** (128-bit entropy, hex format), **expirations/renewals**, and seat control via **LicenseUsage** (usage = one seat).
- **Offline verification** using public-key–signed tokens and a **two-level key hierarchy** (**root → signing**) for safe rotation & compromise handling.
- **License Scopes** for multi-product/software key isolation.
- **Trial management** with HMAC-SHA256 fingerprint hashing and conversion tracking.
- **Template-based licensing** with scope linkage.
- **Out of scope**: multi-tenant isolation, billing/invoicing, advanced entitlement management (hook points only).

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
- Activation keys: store **HMAC-SHA256 hash** only; constant-time comparisons; 128-bit entropy keys generated with `random_bytes()`.
- License key format: `PREFIX-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX` (8 hex chars per segment).
- Offline tokens: signed only; client holds **public** material; no private keys client-side.
- Two-level hierarchy:
  - **Root (pub/priv)** = trust anchor, **never** signs tokens; used to sign **signing-key certificates**.
  - **Signing key (pub/priv)** = signs offline tokens; short-lived; includes `kid`; distributed with a **chain** up to root.
  - KIDs generated with `bin2hex(random_bytes(16))` — cryptographically unpredictable.
- Rotation & compromise:
  - New signing key issued; old marked **revoked**; tokens switch to new `kid`.
  - Clients validating with **root public** continue to work offline (chain validates).
- Rate limiting: applied via named middleware (`licensing-validate`, `licensing-register`, `licensing-token`) on all API routes.
- Concurrency: enforce `max_usages` via **pessimistic locks** during registration.
- API error sanitization: internal exception messages never exposed to clients; errors logged server-side via `report()`.
- Health endpoint: returns only `status: ok/error` per check — no KID, validity dates, or error details exposed.
- Fingerprint validation: `max:255` enforced on all API inputs.
- Trial fingerprints: stored as **HMAC-SHA256** (with `app.key`); legacy SHA256 fallback for backward compatibility.
- Heartbeat metadata: client-provided data namespaced under `client_data` key to prevent meta injection.
- Privacy: `ip`/`user_agent` optional; retention window configurable; avoid PII in fingerprints.
- Clock skew tolerance ±60s in token validation.
- Audit trail: append-only records for key lifecycle, license state changes, usage events.

---

## Fingerprint & usage policy
- `usage_fingerprint` must be **stable**, **repeatable**, **non-PII**, and **max 255 characters**.
- Default uniqueness: **per license**; allow **global** uniqueness via config.
- Over-limit default: **reject**; optional **auto_replace_oldest** (revokes the least recent active usage).
- Heartbeat updates `last_seen_at`; client data stored under `meta.client_data` (not merged at root); optional auto-revoke after prolonged inactivity.

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
All endpoints rate-limited via named middleware. Error responses never expose internal exception details.
- `POST /activate` → activate license + register usage; returns token if offline enabled. Middleware: `throttle:licensing-register`.
- `POST /deactivate` → revoke usage. Middleware: `throttle:licensing-register`.
- `POST /refresh` → refresh offline token for active usage. Middleware: `throttle:licensing-token`.
- `POST /validate` → online license + fingerprint check. Middleware: `throttle:licensing-validate`.
- `POST /heartbeat` → update usage `last_seen_at` + client metadata. Middleware: `throttle:licensing-validate`.
- `POST /licenses/show` → license details (requires `license_key` + `fingerprint`). Middleware: `throttle:licensing-validate`.
- `POST /token` → issue offline token. Middleware: `throttle:licensing-token`.
- `GET /health` → system health (returns only `status: ok/error` per check, no sensitive details).
- Errors: JSON with `code`, `message`. Generic messages for 500s; specific codes for business errors (`INVALID_KEY`, `USAGE_LIMIT_REACHED`, etc.).

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

## Deliverables (v2)
- Overridable models, migrations, and policies per specs.
- CLI for key lifecycle and offline token issuance.
- Optional API endpoints with rate limiting middleware applied by default.
- Documentation: security model, rotation procedure, offline usage, config reference, UPGRADE.md.
- Test suite: 249+ tests covering security, lifecycle, API, and CLI.
- Laravel 12/13 support; PHP 8.3–8.5; CI matrix on GitHub Actions.

---

## Glossary
- **LicenseUsage**: one consumed seat (device/VM/service/user).
- **Fingerprint**: stable, non-PII identifier bound to a usage.
- **Root key**: trust anchor; signs signing-key certificates only.
- **Signing key**: signs offline tokens; short-lived; rotatable via `kid`.
- **Chain**: certificate data linking signing key to root.


