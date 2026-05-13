# Laravel Boost Guidelines Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Laravel Boost-discoverable AI guidelines inside the package so consumer apps auto-load them via `boost:install` / `boost:update --discover`.

**Architecture:** 8 static Blade files under `resources/boost/guidelines/laravel-licensing/`, one Pest test that compile-smoke-renders each file via `Blade::render`, README section, CHANGELOG entry. No source code changes, no new runtime deps.

**Tech Stack:** Laravel Blade engine, Pest 2/3, Orchestra Testbench, Laravel Boost (consumer side).

---

## File Structure

- Create: `resources/boost/guidelines/laravel-licensing/core.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/licenses.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/usages.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/scopes-templates.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/trials.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/offline-tokens.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/cli.blade.php`
- Create: `resources/boost/guidelines/laravel-licensing/api-security.blade.php`
- Create: `tests/Unit/BoostGuidelinesTest.php`
- Modify: `README.md` (append "Laravel Boost" section)
- Modify: `CHANGELOG.md` (Unreleased entry)

---

### Task 1: Compile-smoke test (failing) for all 8 guideline files

**Files:**
- Test: `tests/Unit/BoostGuidelinesTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/BoostGuidelinesTest.php`:

```php
<?php

use Illuminate\Support\Facades\Blade;

const GUIDELINES_DIR = __DIR__.'/../../resources/boost/guidelines/laravel-licensing';

const GUIDELINE_FILES = [
    'core.blade.php',
    'licenses.blade.php',
    'usages.blade.php',
    'scopes-templates.blade.php',
    'trials.blade.php',
    'offline-tokens.blade.php',
    'cli.blade.php',
    'api-security.blade.php',
];

it('ships all expected Boost guideline files', function () {
    foreach (GUIDELINE_FILES as $file) {
        expect(GUIDELINES_DIR.'/'.$file)->toBeFile();
    }
});

it('compiles every Boost guideline file via Blade without errors', function (string $file) {
    $contents = file_get_contents(GUIDELINES_DIR.'/'.$file);

    expect($contents)->not->toBeEmpty();

    $rendered = Blade::render($contents, [], deleteCachedView: true);

    expect($rendered)->toBeString()->not->toBeEmpty();
})->with(GUIDELINE_FILES);

it('mentions key package concepts in core guideline', function () {
    $core = file_get_contents(GUIDELINES_DIR.'/core.blade.php');

    expect($core)
        ->toContain('laravel-licensing')
        ->toContain('License')
        ->toContain('LicenseUsage')
        ->toContain('licensable');
});
```

- [ ] **Step 2: Run test, expect failure**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
Expected: FAIL (files do not exist yet).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/BoostGuidelinesTest.php
git commit -m "Add failing test for Boost guideline files"
```

---

### Task 2: `core.blade.php` — overview, install, entities, lifecycle

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/core.blade.php`

- [ ] **Step 1: Create file**

```blade
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
```

- [ ] **Step 2: Re-run test**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php --filter "core"`
Expected: PASS for "mentions key package concepts in core guideline" and "ships all expected" (still partial), PASS for the per-file compile of `core.blade.php`.

- [ ] **Step 3: Commit**

```bash
git add resources/boost/guidelines/laravel-licensing/core.blade.php
git commit -m "Add Boost core guideline"
```

---

### Task 3: `licenses.blade.php` — keys, hashing, activation, renewal

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/licenses.blade.php`

- [ ] **Step 1: Create file**

```blade
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
```

- [ ] **Step 2: Test**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
Expected: 2 of 8 dataset entries pass, others still fail.

- [ ] **Step 3: Commit**

```bash
git add resources/boost/guidelines/laravel-licensing/licenses.blade.php
git commit -m "Add Boost licenses guideline"
```

---

### Task 4: `usages.blade.php` — seats, fingerprint, over-limit, heartbeat

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/usages.blade.php`

- [ ] **Step 1: Create file**

```blade
# laravel-licensing — Usages (Seats)

One `LicenseUsage` row = one consumed seat (device/VM/service/user/session).

## Register
```php
$usage = $license->registerUsage(
    fingerprint: $fingerprint,                // stable, non-PII, max 255 chars
    clientType: 'desktop',
    name: 'Luca\'s MacBook',
);
```
The package wraps registration in a pessimistic lock so `max_usages` cannot be exceeded.

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
$usage->heartbeat(clientData: ['app_version' => '2.1.0']);
```
Client-supplied fields are namespaced under `meta.client_data`. **Never** merge raw client input into `meta` root.

## Auto-revoke (optional)
`config('licensing.policies.usage_inactivity_auto_revoke_days') = 30` to revoke usages whose `last_seen_at` is older than N days.

## Rules
- DON'T derive fingerprints from PII (email, MAC, full UA).
- DON'T register usages outside the package service — pessimistic lock guarantees only hold there.
- DO call `revoke()` on logout/uninstall to free a seat.
```

- [ ] **Step 2: Test**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
Expected: 3 of 8 dataset entries pass.

- [ ] **Step 3: Commit**

```bash
git add resources/boost/guidelines/laravel-licensing/usages.blade.php
git commit -m "Add Boost usages guideline"
```

---

### Task 5: `scopes-templates.blade.php`

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/scopes-templates.blade.php`

- [ ] **Step 1: Create file**

```blade
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
use LucaLongo\Licensing\Models\LicenseTemplate;

$template = LicenseTemplate::create([
    'license_scope_id' => $scope->id,
    'name'             => 'Pro 1-year, 3 seats',
    'max_usages'       => 3,
    'duration_days'    => 365,
    'meta'             => ['plan' => 'pro'],
]);

$license = $template->issue(licensable: $user);
```

## Rules
- DO create one scope per product or product line.
- DON'T reuse a scope across unrelated products — defeats the isolation purpose.
- DO issue licenses through templates when configuration is repetitive.
```

- [ ] **Step 2: Test & commit**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
Then:
```bash
git add resources/boost/guidelines/laravel-licensing/scopes-templates.blade.php
git commit -m "Add Boost scopes & templates guideline"
```

---

### Task 6: `trials.blade.php`

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/trials.blade.php`

- [ ] **Step 1: Create file**

```blade
# laravel-licensing — Trials

## Start a trial
```php
use LucaLongo\Licensing\Models\LicenseTrial;

$trial = LicenseTrial::start(
    licensable: $user,
    fingerprint: $fingerprint,
    durationDays: 14,
    scope: $scope,                            // optional
);
```

## Fingerprint hashing
- Stored as **HMAC-SHA256** with `config('app.key')` as the key.
- Legacy SHA256 fallback for backward compatibility.
- Same fingerprint stable across trial → conversion to keep attribution.

## Conversion
```php
$license = $trial->convertTo($template);      // links trial to issued license
```
The trial row is preserved (audit), `converted_at` is set.

## Rules
- DON'T reuse the same fingerprint across scopes unless you explicitly want shared trial limits.
- DO check `LicenseTrial::hasActiveTrial($fingerprint, $scope)` before offering a new trial.
- DON'T store raw fingerprints anywhere — only HMAC.
```

- [ ] **Step 2: Test & commit**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
```bash
git add resources/boost/guidelines/laravel-licensing/trials.blade.php
git commit -m "Add Boost trials guideline"
```

---

### Task 7: `offline-tokens.blade.php`

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/offline-tokens.blade.php`

- [ ] **Step 1: Create file**

```blade
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
```

- [ ] **Step 2: Test & commit**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
```bash
git add resources/boost/guidelines/laravel-licensing/offline-tokens.blade.php
git commit -m "Add Boost offline tokens guideline"
```

---

### Task 8: `cli.blade.php`

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/cli.blade.php`

- [ ] **Step 1: Create file**

```blade
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
```

- [ ] **Step 2: Test & commit**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
```bash
git add resources/boost/guidelines/laravel-licensing/cli.blade.php
git commit -m "Add Boost CLI guideline"
```

---

### Task 9: `api-security.blade.php`

**Files:**
- Create: `resources/boost/guidelines/laravel-licensing/api-security.blade.php`

- [ ] **Step 1: Create file**

```blade
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
```

- [ ] **Step 2: Test — all 8 should now pass**

Run: `vendor/bin/pest tests/Unit/BoostGuidelinesTest.php`
Expected: all assertions PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/boost/guidelines/laravel-licensing/api-security.blade.php
git commit -m "Add Boost API & security guideline"
```

---

### Task 10: README "Laravel Boost" section

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Append section before the "License" / final footer section**

Add at an appropriate spot (after "Features" usage examples, before contributing/license footer):

```markdown
## Laravel Boost integration

This package ships AI guidelines under `resources/boost/guidelines/laravel-licensing/`. Apps using [Laravel Boost](https://github.com/laravel/boost) auto-discover them:

```bash
php artisan boost:install            # first time, or
php artisan boost:update --discover  # to pick up after adding the package
```

The guidelines cover: core concepts, licenses, usages/seats, scopes & templates, trials, offline tokens, CLI, and API/security. AI assistants (Claude Code, Copilot, Cursor, …) will follow them when generating code against `laravel-licensing`.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "Document Laravel Boost integration in README"
```

---

### Task 11: CHANGELOG entry

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Add Unreleased section**

Open `CHANGELOG.md`. If no `## [Unreleased]` section exists at the top, create one above the latest released version block. Add:

```markdown
## [Unreleased]

### Added
- Laravel Boost AI guidelines under `resources/boost/guidelines/laravel-licensing/` (auto-discovered by `boost:install` / `boost:update --discover`).
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "Changelog: Laravel Boost guidelines"
```

---

### Task 12: Final full-suite verification

- [ ] **Step 1: Run the entire test suite**

Run: `vendor/bin/pest`
Expected: all green, including the new `BoostGuidelinesTest`.

- [ ] **Step 2: Final sanity grep**

Run: `ls resources/boost/guidelines/laravel-licensing/`
Expected: 8 `.blade.php` files listed.

- [ ] **Step 3: If anything fails**

Fix in place, re-run, re-commit with a focused message. Do NOT amend prior commits.

---

## Self-Review

- **Spec coverage:** all 8 files from spec produced (core, licenses, usages, scopes-templates, trials, offline-tokens, cli, api-security). README + CHANGELOG covered. Compile-smoke test covered.
- **No placeholders:** every Blade snippet is complete. Test code is complete.
- **Type consistency:** model FQNs (`LucaLongo\Licensing\Models\*`) consistent across files; config keys (`licensing.policies.over_limit`, `licensing.offline_token.ttl_days`, etc.) match `CLAUDE.md` spec.
- **No source code changes:** plan is pure docs + tests, matches spec "out of scope" exclusions.
