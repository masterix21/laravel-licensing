# Upgrade Guide

## Upgrading within 2.1.x

### Audit chain hash formula changed

The tamper-evident audit log now includes the forensic attribution columns
(`actor`, `actor_type`, `actor_id`, `ip`, `user_agent`, `occurred_at`) in
`calculateHash()`. Previously these columns were stored but left out of the hash,
so a raw `UPDATE` could rewrite who performed an action and when without breaking
`verifyChain()`.

**Impact:** the hash value for any given record changes. Records written before
this upgrade will no longer verify against records written after it — the chain
effectively re-bases from the deploy point forward.

**Action required:**

- If you have **no persisted audit history** (fresh install, or audit logs you do
  not need to retain), no action is needed.
- If you **rely on an existing chain**, treat the upgrade as a chain boundary:
  archive the pre-upgrade segment (its internal links remain valid under the old
  formula) and start a new chain from the first record written after the upgrade.
  Do not attempt to re-verify across the boundary.

---

## Upgrading to 2.0 from 1.x

### Breaking Changes

#### 1. License detail endpoint changed from GET to POST

The `licenses.show` endpoint now requires authentication via fingerprint.

**Before (1.x):**
```http
GET /api/licensing/v1/licenses/{licenseKey}
```

**After (2.0):**
```http
POST /api/licensing/v1/licenses/show
Content-Type: application/json

{
    "license_key": "LIC-A3F2B9K1-C4D8E5H7-9D2EK8F3-L6A9M1B4",
    "fingerprint": "your-device-fingerprint"
}
```

**Action required:** Update all client code that calls this endpoint. The endpoint now returns `403` if the fingerprint does not match an active usage for the license.

---

#### 2. Heartbeat metadata stored under `client_data` key

Client-provided data in heartbeat requests is now namespaced under `client_data` in the usage `meta` field, preventing clients from overwriting internal metadata keys.

**Before (1.x):**
```json
// Heartbeat request
{ "data": { "app_version": "1.2.3" } }

// Stored in meta as:
{ "app_version": "1.2.3", "internal_flag": "value" }
```

**After (2.0):**
```json
// Same heartbeat request
{ "data": { "app_version": "1.2.3" } }

// Stored in meta as:
{ "internal_flag": "value", "client_data": { "app_version": "1.2.3" } }
```

**Action required:** Update any code that reads heartbeat data from `$usage->meta['key']` to `$usage->meta['client_data']['key']`.

If you have existing data stored at root level from 1.x, it remains there untouched. New heartbeat calls will write to `client_data`. You may want to migrate existing data with a one-time script:

```php
LicenseUsage::whereNotNull('meta')->each(function ($usage) {
    $meta = $usage->meta;
    if (isset($meta['client_data'])) {
        return; // Already migrated
    }

    // Move non-internal keys to client_data
    $internalKeys = ['internal_flag']; // Add your internal keys here
    $clientData = array_diff_key($meta, array_flip($internalKeys));

    if (empty($clientData)) {
        return;
    }

    $meta['client_data'] = $clientData;
    foreach (array_keys($clientData) as $key) {
        unset($meta[$key]);
    }

    $usage->update(['meta' => $meta]);
});
```

---

#### 3. Health endpoint no longer exposes key details

The health endpoint response no longer includes `kid`, `valid_until`, or database error messages. Each check now returns only `status: ok` or `status: error`.

**Before (1.x):**
```json
{
    "checks": {
        "root_key": { "status": "ok", "kid": "root-abc", "valid_until": "2027-01-01" },
        "signing_key": { "status": "ok", "kid": "signing-def", "valid_until": "2026-05-01" }
    }
}
```

**After (2.0):**
```json
{
    "checks": {
        "root_key": { "status": "ok" },
        "signing_key": { "status": "ok" }
    }
}
```

**Action required:** Update any monitoring that depends on `kid` or `valid_until` from the health endpoint. Use `licensing:keys:list` CLI command instead.

---

### Non-Breaking Changes (no action required)

#### License key format

New keys are generated with higher entropy (`LIC-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX`, 128-bit). Existing keys continue to work because verification uses `key_hash`, which is unchanged.

#### Trial fingerprint hashing

New trials use HMAC-SHA256 instead of plain SHA256 for fingerprint storage. Existing trials with SHA256 hashes are still found and verified via automatic legacy fallback. No migration needed.

#### Rate limiting

API endpoints now have rate limiting applied by default. See `config/licensing.php` under `rate_limit` to adjust limits.

#### Fingerprint validation

API endpoints now reject fingerprints longer than 255 characters. This should not affect normal usage.

#### KID generation

Auto-generated Key IDs for signing keys now use cryptographically secure random hex instead of `uniqid()`. Existing KIDs are unaffected.

#### Error messages

API error responses no longer expose internal exception details. Error codes (`INVALID_KEY`, `TOKEN_ISSUE_FAILED`, etc.) remain the same.
