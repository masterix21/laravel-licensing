# Laravel Boost Guidelines — Design Spec

**Date**: 2026-05-13
**Package**: `masterix21/laravel-licensing`
**Target**: Laravel Boost ≥ current stable

## Goal

Ship AI guidelines inside the package so consumer apps using Laravel Boost auto-discover them via `boost:install` / `boost:update --discover`. Guidelines instruct AI assistants (Claude Code, Copilot, Cursor, etc.) on correct API usage, security defaults, and lifecycle conventions of `laravel-licensing`.

## Discovery mechanism

Boost scans installed packages for `resources/boost/guidelines/<package>/*.blade.php`. Files are rendered through the Blade engine and injected into the AI agent's context. Extension `.blade.php` is required by Boost's discovery — static markdown content is allowed inside.

## Location

```
resources/boost/guidelines/laravel-licensing/
├── core.blade.php
├── licenses.blade.php
├── usages.blade.php
├── scopes-templates.blade.php
├── trials.blade.php
├── offline-tokens.blade.php
├── cli.blade.php
└── api-security.blade.php
```

## Content style

- Terse, imperative rules. No prose.
- Pattern per section: heading → `Use:` / `Don't:` bullets → copy-paste PHP snippet.
- Static content; no Blade directives unless a version-conditional block becomes necessary.
- Each file self-contained; assume AI may load only one file at a time.
- Reference exact class names with FQN on first mention, short name after.

## File breakdown

### 1. `core.blade.php`

- Requirements: PHP 8.3–8.5, Laravel 12/13.
- Install: `composer require masterix21/laravel-licensing`.
- Publish: `php artisan vendor:publish --tag=licensing-config|licensing-migrations`.
- Entities: `License`, `LicenseUsage`, `LicenseRenewal`, `LicenseScope`, `LicenseTemplate`, `LicenseTrial`.
- Polymorphic `licensable` morphTo — bind license to any model.
- Morph map: register aliases via `config('licensing.morph_map')` to hide app class names.
- Lifecycle states: `pending → active → grace → expired`; side: `suspended`, `cancelled`.
- Timestamps UTC.

### 2. `licenses.blade.php`

- Key format: `PREFIX-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX` (hex, 128-bit entropy via `random_bytes`).
- Storage: `key_hash` = HMAC-SHA256 only. Never store plaintext.
- Comparison: constant-time.
- Create + activate snippet (call resolved `LicenseService` from container, never `new`).
- Renew: extends `expires_at`, records `LicenseRenewal`.
- State transitions: nightly scheduler `licensing:check-expirations`.
- Events: `LicenseActivated`, `LicenseExpiringSoon`, `LicenseExpired`, `LicenseRenewed`.

### 3. `usages.blade.php`

- One `LicenseUsage` = one seat (device/VM/service/user/session).
- Register usage: pessimistic lock enforces `max_usages`.
- `usage_fingerprint`: stable, repeatable, non-PII, `max:255` chars.
- Uniqueness scope: `license` (default) | `global`, via `config('licensing.policies.unique_usage_scope')`.
- Over-limit: `reject` (default) | `auto_replace_oldest`.
- Heartbeat updates `last_seen_at`; client-provided fields stored under `meta.client_data` (never merged at root).
- Optional auto-revoke after inactivity: `usage_inactivity_auto_revoke_days`.
- Events: `UsageRegistered`, `UsageRevoked`, `UsageLimitReached`.

### 4. `scopes-templates.blade.php`

- `LicenseScope`: isolates licenses per product/software; query by scope.
- `LicenseTemplate`: prebuilt license configuration linked to a scope; uses `spatie/laravel-sluggable` v4.
- Snippet: create scope, create template, issue license from template.

### 5. `trials.blade.php`

- Start trial bound to fingerprint.
- Fingerprint stored as HMAC-SHA256 with `app.key`; legacy SHA256 fallback for backward compatibility.
- Conversion tracking: link trial → activated license.
- Don't reuse trial fingerprint across scopes unless intended.

### 6. `offline-tokens.blade.php`

- Format: PASETO v4.public (default) or JWS (`config('licensing.offline_token.format')`).
- Two-level hierarchy: root (trust anchor, signs signing-key certs only) → signing key (signs tokens, short-lived, has `kid`).
- Claims: `license_id`, `license_key_hash`, `usage_fingerprint`, `status`, `exp`, `nbf`, `iat`, `max_usages`, optional `grace_until`, `entitlements`, `licensable_ref`, `serial`.
- Headers: `kid`, `chain`, `version`.
- TTL default 7d; `force_online_after_days` 14; clock skew ±60s.
- Client holds **public** material only.
- Rotation: new signing key issued → old `revoked_at` set → clients with root public validate via chain.

### 7. `cli.blade.php`

- `licensing:keys:make-root` — root keypair.
- `licensing:keys:issue-signing --kid K1 [--nbf ISO --exp ISO]`.
- `licensing:keys:rotate --reason <routine|compromised>`.
- `licensing:keys:revoke <KID> [--at ISO]`.
- `licensing:keys:list`.
- `licensing:keys:export --format <jwks|pem|json> [--include-chain]`.
- `licensing:offline:issue --license <id|key> --fingerprint <fp> --ttl 7d`.
- Maintenance: `licensing:check-expirations`, `licensing:cleanup-inactive-usages`, `licensing:notify-expiring`.
- Return codes: `0` success, `1` invalid args, `2` not found, `3` revoked/compromised, `4` I/O or crypto error.
- Store private keys encrypted via `LICENSING_KEY_PASSPHRASE` env.

### 8. `api-security.blade.php`

- Prefix `/api/licensing/v1`.
- Endpoints: `POST /activate`, `/deactivate`, `/refresh`, `/validate`, `/heartbeat`, `/licenses/show`, `/token`; `GET /health`.
- Throttle middleware: `throttle:licensing-validate` (validate, heartbeat, show), `throttle:licensing-register` (activate, deactivate), `throttle:licensing-token` (refresh, token).
- Error contract: JSON `{code, message}`. Generic messages for 500s; business codes (`INVALID_KEY`, `USAGE_LIMIT_REACHED`, …).
- DO:
  - Validate `fingerprint` with `max:255`.
  - Report internal exceptions via `report()`.
  - Use container-resolved services (overridable via contracts).
  - Use morph map for `licensable`.
- DON'T:
  - Expose internal exception messages to clients.
  - Return KID, validity dates, or error details from `/health`.
  - Log PII in audit trail.
  - Bypass pessimistic lock in usage registration.
  - Concat `meta` from client at root (use `meta.client_data`).

## Composer / discovery wiring

No `composer.json` changes required — Boost scans by convention. README gets a "Laravel Boost" section explaining auto-discovery on `boost:install`.

## Testing

- Unit test: render each Blade file via `view()->file()` to verify it compiles (catches accidental directive errors).
- Snapshot test optional: assert rendered output contains key tokens (e.g., `max_usages`, `kid`).

## Out of scope

- Boost MCP custom tools.
- Claude Code `.claude/skills` shipping.
- Translations of guidelines (English only, matches Boost convention).

## Definition of Done

- 8 Blade files under `resources/boost/guidelines/laravel-licensing/`.
- README updated with Boost section.
- Compile-smoke test green.
- CHANGELOG entry under Unreleased.
