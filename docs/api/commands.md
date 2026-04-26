# API Reference: Commands

This document provides comprehensive API reference for all Artisan commands in the Laravel Licensing package. Commands provide CLI interfaces for key management, token operations, and maintenance tasks.

## Table of Contents

- [Key Management Commands](#key-management-commands)
- [Token Commands](#token-commands)
- [Maintenance Commands](#maintenance-commands)
- [Command Options](#command-options)
- [Exit Codes](#exit-codes)
- [Automation](#automation)

## Key Management Commands

Commands for managing cryptographic keys and certificates.

### licensing:keys:make-root

Creates a new root key pair for the certificate authority.

```bash
php artisan licensing:keys:make-root [options]
```

**Options:**
- `--force` - Overwrite an existing root key
- `--silent` - Do not prompt for a missing passphrase

The keystore passphrase is read from the env var configured by
`licensing.crypto.keystore.passphrase_env` (default `LICENSING_KEY_PASSPHRASE`).
The signing algorithm comes from `licensing.crypto.algorithm`.

**Example:**

```bash
# Create root key
php artisan licensing:keys:make-root

# Force overwrite existing root key
php artisan licensing:keys:make-root --force
```

**Output:**

```
Root key generated successfully!

Key ID: root-2024-01-15-abc123
Algorithm: ed25519
Public Key Path: /storage/app/licensing/keys/root-public.pem
Private Key: Encrypted and stored securely

Bundle exported to: /storage/app/licensing/public-bundle.json
```

### licensing:keys:issue-signing

Issues a new signing key signed by the root key.

```bash
php artisan licensing:keys:issue-signing [options]
```

**Options:**
- `--kid=KEY_ID` - Custom key identifier (auto-generated when omitted)
- `--scope=SLUG` - Scope slug or identifier for the signing key
- `--days=N` - Validity window in days (shortcut for `--nbf=now --exp=now+N`)
- `--nbf=DATETIME` - Valid from (ISO 8601)
- `--exp=DATETIME` - Valid until (ISO 8601)

The new key is activated immediately.

**Example:**

```bash
# Issue signing key with auto-generated kid
php artisan licensing:keys:issue-signing

# Issue with explicit kid and validity window
php artisan licensing:keys:issue-signing \
  --kid=signing-2024-q1 \
  --nbf=2024-01-01T00:00:00Z \
  --exp=2024-04-01T00:00:00Z

# Issue scoped to a specific product
php artisan licensing:keys:issue-signing --scope=erp-system --days=90
```

### licensing:keys:rotate

Rotates the current signing key (revokes old, issues new).

```bash
php artisan licensing:keys:rotate [options]
```

**Options:**
- `--reason=routine|compromised` - Rotation reason (defaults to `routine`)
- `--immediate` - Immediately revoke the old key (required for `compromised`)

**Example:**

```bash
# Routine rotation
php artisan licensing:keys:rotate --reason=routine

# Emergency rotation for compromised key
php artisan licensing:keys:rotate --reason=compromised --immediate
```

### licensing:keys:revoke

Revokes a specific key.

```bash
php artisan licensing:keys:revoke {kid} [options]
```

**Arguments:**
- `kid` - Key identifier to revoke

**Options:**
- `--reason=manual` - Revocation reason (defaults to `manual`)
- `--at=DATETIME` - When to revoke (ISO 8601, defaults to now)

**Example:**

```bash
# Revoke immediately
php artisan licensing:keys:revoke signing-2024-01

# Backdate revocation
php artisan licensing:keys:revoke signing-2024-01 \
  --at=2024-02-01T00:00:00Z \
  --reason=key-rotation
```

### licensing:keys:list

Lists all keys with their status and validity.

```bash
php artisan licensing:keys:list [options]
```

Lists every root and signing key (active, revoked, and expired) in a table.
Has no options.

**Example:**

```bash
php artisan licensing:keys:list
```

**Sample Output:**

```
+------------------+----------+--------+---------------------+---------------------+
| Key ID           | Type     | Status | Valid From          | Valid Until         |
+------------------+----------+--------+---------------------+---------------------+
| root-2024-abc123 | root     | active | 2024-01-15 10:00:00 | 2026-01-15 10:00:00 |
| sign-2024-xyz789 | signing  | active | 2024-01-15 10:05:00 | 2024-04-15 10:05:00 |
| sign-2023-old123 | signing  | revoked| 2023-10-01 09:00:00 | 2024-01-01 09:00:00 |
+------------------+----------+--------+---------------------+---------------------+
```

### licensing:keys:export

Exports public key materials for client distribution.

```bash
php artisan licensing:keys:export [options]
```

**Options:**
- `--format=json|jwks|pem` - Export format (defaults to `json`)
- `--include-chain` - Include certificate chain in the bundle

The bundle is written to the path configured by `licensing.publishing.public_bundle_path`.

**Example:**

```bash
# Export as JWKS
php artisan licensing:keys:export --format=jwks

# Export PEM bundle with chain
php artisan licensing:keys:export --format=pem --include-chain
```

## Token Commands

Commands for offline token operations.

### licensing:offline:issue

Issues offline verification tokens.

```bash
php artisan licensing:offline:issue [options]
```

**Options:**
- `--license=ID` - License ID or activation key (required)
- `--fingerprint=FP` - Usage fingerprint (required)
- `--ttl=7d` - Token time-to-live (defaults to `7d`)

**Example:**

```bash
php artisan licensing:offline:issue \
  --license=LIC-ABC123-XYZ789 \
  --fingerprint=device-unique-id \
  --ttl=14d
```

**Output:**

```
Offline token issued successfully!

License: LIC-ABC123-XYZ789
Fingerprint: device-unique-id
Issued At: 2024-01-15 10:00:00 UTC
Expires At: 2024-01-29 10:00:00 UTC
Token: v4.public.eyJ0eXAiOiJQQVNFVE8iLCJhbGc...

Token saved to: token.txt
```

### Verifying Offline Tokens

There is no CLI verifier. Verify tokens programmatically through the `TokenVerifier`
contract (resolved via `app(\LucaLongo\Licensing\Contracts\TokenVerifier::class)`),
which validates signature, chain, expiry, and clock skew.

## Maintenance Commands

Commands for system maintenance and monitoring.

### licensing:check-expirations

Transitions licenses across grace and expired states based on `expires_at`.

```bash
php artisan licensing:check-expirations [options]
```

**Options:**
- `--dry-run` - Report transitions without applying them
- `--notify` - Dispatch `LicenseExpiringSoon` events for licenses near expiration
- `--expiring-within=7` - Days threshold for expiring-soon notifications

**Example:**

```bash
# Dry run to see what licenses would be affected
php artisan licensing:check-expirations --dry-run

# Apply transitions and notify upcoming expirations
php artisan licensing:check-expirations --notify --expiring-within=14
```

### licensing:cleanup-usages

Revokes license usages whose `last_seen_at` exceeds the configured inactivity
threshold (`licensing.policies.usage_inactivity_auto_revoke_days`).

```bash
php artisan licensing:cleanup-usages [options]
```

**Options:**
- `--dry-run` - Report revocations without applying them

If `usage_inactivity_auto_revoke_days` is `null`, the command exits as a no-op.

**Example:**

```bash
# Apply revocations
php artisan licensing:cleanup-usages

# Preview without applying
php artisan licensing:cleanup-usages --dry-run
```

### licensing:check

Verifies installation: configuration, schema, root key, and active signing key.

```bash
php artisan licensing:check
```

Exit code is `0` when every check passes and `1` otherwise. Output is a table
with one row per check (Configuration, each licensing table, Root key, Signing
key) and a remediation hint for failing rows.

## Command Options

### Global Options

All commands support these global options:

- `--help` - Show command help
- `--quiet` - Suppress output
- `--verbose` - Increase verbosity (-v, -vv, -vvv)
- `--no-interaction` - Don't ask interactive questions
- `--env=testing` - Specify environment

### Date/Time Formats

Commands accepting date/time values support these formats:

- ISO 8601: `2024-01-15T10:00:00Z`
- Relative: `+30 days`, `-1 week`, `now`
- Human readable: `next monday`, `tomorrow 2pm`

### Duration Formats

Time-to-live and duration options accept:

- Days: `7d`, `30d`
- Hours: `24h`, `168h`
- Minutes: `1440m`
- Seconds: `86400s`

## Exit Codes

Commands follow standard exit code conventions:

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | General error or invalid arguments |
| 2 | Resource not found (license, key, etc.) |
| 3 | Resource is revoked or invalid |
| 4 | I/O error (file system, network) |
| 5 | Cryptographic error |
| 6 | Permission denied |
| 7 | Resource already exists (with --no-overwrite) |

**Example usage in scripts:**

```bash
#!/bin/bash

# Issue signing key and check result
php artisan licensing:keys:issue-signing --kid=quarterly-2024-q1

case $? in
    0) echo "Key issued successfully" ;;
    1) echo "Invalid arguments provided" ;;
    7) echo "Key already exists" ;;
    *) echo "Unexpected error occurred" ;;
esac
```

## Automation

### Scheduled Commands

Add to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Check expirations daily at 2 AM
    $schedule->command('licensing:check-expirations --notify')
             ->dailyAt('02:00')
             ->withoutOverlapping();
    
    // Rotate signing keys quarterly
    $schedule->command('licensing:keys:rotate --reason=routine')
             ->quarterly()
             ->environments(['production']);
    
    // Clean up inactive usages weekly
    $schedule->command('licensing:cleanup-usages')
             ->weeklyOn(1, '03:00'); // Mondays at 3 AM

    // Installation sanity check daily
    $schedule->command('licensing:check')
             ->daily()
             ->sendOutputTo('/var/log/licensing-check.log');
}
```

### CI/CD Integration

```yaml
# .github/workflows/deploy.yml
- name: Setup License Keys
  run: |
    php artisan licensing:keys:make-root --force --silent
    php artisan licensing:keys:issue-signing --no-interaction

- name: Installation Check
  run: php artisan licensing:check
```

### Key Rotation Automation

```php
// app/Console/Commands/AutoRotateKeys.php
class AutoRotateKeys extends Command
{
    protected $signature = 'licensing:auto-rotate-keys';
    
    public function handle()
    {
        $activeKey = LucaLongo\Licensing\Models\LicensingKey::findActiveSigning();

        if (! $activeKey) {
            $this->error('No active signing key found');

            return 1;
        }

        if ($activeKey->created_at->diffInDays(now()) > 60) {
            $this->info('Rotating signing key (60+ days old)');

            $this->call('licensing:keys:rotate', [
                '--reason' => 'routine',
            ]);

            return 0;
        }

        $this->info('Key rotation not needed');

        return 0;
    }
}
```

### Backup Commands

```bash
# Backup key material (be very careful with private keys!)
php artisan licensing:keys:export --format=json --include-chain > keys-backup.json

# Backup database
php artisan db:dump --database=licensing

# Verify installation before backup
php artisan licensing:check
```

This comprehensive command reference provides all the tools needed for managing a Laravel Licensing installation through the command line, including automation and monitoring capabilities.