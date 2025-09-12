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
- `--algorithm=ed25519` - Key algorithm (ed25519, ES256)
- `--passphrase=SECRET` - Passphrase for private key encryption
- `--force` - Overwrite existing root key
- `--output-format=json` - Output format (json, text)

**Example:**

```bash
# Create root key with default settings
php artisan licensing:keys:make-root

# Create with custom algorithm and passphrase
php artisan licensing:keys:make-root --algorithm=ES256 --passphrase=mypassphrase

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
- `--kid=KEY_ID` - Custom key identifier
- `--nbf=DATETIME` - Valid from (ISO 8601 format)
- `--exp=DATETIME` - Valid until (ISO 8601 format)
- `--algorithm=ed25519` - Key algorithm
- `--auto-activate` - Automatically activate the new key

**Example:**

```bash
# Issue signing key with auto-generated ID
php artisan licensing:keys:issue-signing

# Issue with custom validity period
php artisan licensing:keys:issue-signing \
  --kid=signing-2024-q1 \
  --nbf=2024-01-01T00:00:00Z \
  --exp=2024-04-01T00:00:00Z

# Issue and activate immediately
php artisan licensing:keys:issue-signing --auto-activate
```

### licensing:keys:rotate

Rotates the current signing key (revokes old, issues new).

```bash
php artisan licensing:keys:rotate [options]
```

**Options:**
- `--reason=REASON` - Rotation reason (routine, compromised, security)
- `--revoke-at=DATETIME` - When to revoke old key (defaults to now)
- `--validity-days=90` - Validity period for new key
- `--force` - Skip confirmation prompt

**Example:**

```bash
# Routine rotation with confirmation
php artisan licensing:keys:rotate --reason=routine

# Emergency rotation for compromised key
php artisan licensing:keys:rotate --reason=compromised --force

# Schedule future revocation
php artisan licensing:keys:rotate \
  --reason=planned \
  --revoke-at=2024-02-01T00:00:00Z
```

### licensing:keys:revoke

Revokes a specific key.

```bash
php artisan licensing:keys:revoke {kid} [options]
```

**Arguments:**
- `kid` - Key identifier to revoke

**Options:**
- `--at=DATETIME` - When to revoke (defaults to now)
- `--reason=REASON` - Revocation reason
- `--force` - Skip confirmation

**Example:**

```bash
# Revoke immediately
php artisan licensing:keys:revoke signing-2024-01

# Schedule future revocation
php artisan licensing:keys:revoke signing-2024-01 \
  --at=2024-02-01T00:00:00Z \
  --reason="Key rotation"
```

### licensing:keys:list

Lists all keys with their status and validity.

```bash
php artisan licensing:keys:list [options]
```

**Options:**
- `--format=table` - Output format (table, json, csv)
- `--status=active` - Filter by status (active, revoked, expired, all)
- `--type=signing` - Filter by type (root, signing, all)
- `--show-revoked` - Include revoked keys in output

**Example:**

```bash
# List all active keys
php artisan licensing:keys:list

# List all keys including revoked
php artisan licensing:keys:list --status=all

# JSON output for scripting
php artisan licensing:keys:list --format=json
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
- `--format=jwks` - Export format (jwks, pem, json)
- `--include-chain` - Include certificate chain
- `--output=FILE` - Output file path
- `--active-only` - Export only active keys

**Example:**

```bash
# Export as JWKS format
php artisan licensing:keys:export --format=jwks --output=public/jwks.json

# Export as PEM bundle
php artisan licensing:keys:export --format=pem --include-chain

# Export active keys only
php artisan licensing:keys:export --active-only
```

## Token Commands

Commands for offline token operations.

### licensing:offline:issue

Issues offline verification tokens.

```bash
php artisan licensing:offline:issue [options]
```

**Options:**
- `--license=ID` - License ID or key
- `--fingerprint=FP` - Usage fingerprint
- `--ttl=7d` - Token time-to-live (7d, 168h, 10080m)
- `--claims=JSON` - Additional claims as JSON
- `--output=FILE` - Save token to file

**Example:**

```bash
# Issue token for specific license and fingerprint
php artisan licensing:offline:issue \
  --license=LIC-ABC123-XYZ789 \
  --fingerprint=device-unique-id \
  --ttl=14d

# Issue with custom claims
php artisan licensing:offline:issue \
  --license=01HZQM5... \
  --fingerprint=mobile-app-123 \
  --claims='{"app_version":"2.1.0","platform":"ios"}' \
  --output=token.txt
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

### licensing:offline:verify

Verifies offline tokens (for testing).

```bash
php artisan licensing:offline:verify {token} [options]
```

**Arguments:**
- `token` - Token to verify (or file path)

**Options:**
- `--show-claims` - Display decoded claims
- `--validate-license` - Also validate against current license state
- `--format=json` - Output format

**Example:**

```bash
# Verify token
php artisan licensing:offline:verify "v4.public.eyJ0eXAiOi..."

# Verify from file with full validation
php artisan licensing:offline:verify token.txt \
  --show-claims \
  --validate-license \
  --format=json
```

## Maintenance Commands

Commands for system maintenance and monitoring.

### licensing:check-expirations

Checks for expired licenses and processes state transitions.

```bash
php artisan licensing:check-expirations [options]
```

**Options:**
- `--dry-run` - Show what would be done without making changes
- `--notify` - Send expiration notifications
- `--grace-period` - Process grace period transitions
- `--batch-size=100` - Process in batches of specified size

**Example:**

```bash
# Dry run to see what licenses would be affected
php artisan licensing:check-expirations --dry-run

# Process expirations with notifications
php artisan licensing:check-expirations --notify --grace-period
```

### licensing:cleanup-inactive-usages

Removes inactive usage records based on inactivity policies.

```bash
php artisan licensing:cleanup-inactive-usages [options]
```

**Options:**
- `--days=30` - Inactivity threshold in days
- `--dry-run` - Show what would be cleaned without making changes
- `--batch-size=100` - Process in batches

**Example:**

```bash
# Clean up usages inactive for 30+ days
php artisan licensing:cleanup-inactive-usages --days=30

# Dry run to see what would be cleaned
php artisan licensing:cleanup-inactive-usages --dry-run
```

### licensing:health-check

Performs comprehensive system health checks.

```bash
php artisan licensing:health-check [options]
```

**Options:**
- `--component=keys` - Check specific component (keys, database, tokens)
- `--format=table` - Output format (table, json)
- `--fail-fast` - Stop on first failure

**Example:**

```bash
# Complete health check
php artisan licensing:health-check

# Check only cryptographic keys
php artisan licensing:health-check --component=keys

# JSON output for monitoring
php artisan licensing:health-check --format=json
```

**Sample Output:**

```
Laravel Licensing System Health Check

✓ Database connectivity
✓ Required tables exist
✓ Root key present and valid
✓ Active signing key available
✓ Key permissions correct
✓ Configuration valid
⚠ 3 licenses expiring within 7 days
✓ No revoked keys in use

Overall Status: HEALTHY (1 warning)
```

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
    $schedule->command('licensing:cleanup-inactive-usages --days=60')
             ->weeklyOn(1, '03:00'); // Mondays at 3 AM
    
    // Health check hourly
    $schedule->command('licensing:health-check --format=json')
             ->hourly()
             ->sendOutputTo('/var/log/licensing-health.log');
}
```

### CI/CD Integration

```yaml
# .github/workflows/deploy.yml
- name: Setup License Keys
  run: |
    php artisan licensing:keys:make-root --force --no-interaction
    php artisan licensing:keys:issue-signing --auto-activate --no-interaction

- name: Health Check
  run: |
    php artisan licensing:health-check --fail-fast
    if [ $? -ne 0 ]; then
      echo "Health check failed"
      exit 1
    fi
```

### Monitoring Scripts

```bash
#!/bin/bash
# monitoring/check-licensing-health.sh

# Run health check and capture output
OUTPUT=$(php artisan licensing:health-check --format=json)
EXIT_CODE=$?

# Parse JSON output
WARNINGS=$(echo $OUTPUT | jq '.warnings | length')
ERRORS=$(echo $OUTPUT | jq '.errors | length')

# Alert if issues found
if [ $WARNINGS -gt 0 ] || [ $ERRORS -gt 0 ]; then
    # Send alert to monitoring system
    curl -X POST https://monitoring.example.com/alert \
         -H "Content-Type: application/json" \
         -d "{\"service\":\"licensing\",\"warnings\":$WARNINGS,\"errors\":$ERRORS}"
fi

exit $EXIT_CODE
```

### Key Rotation Automation

```php
// app/Console/Commands/AutoRotateKeys.php
class AutoRotateKeys extends Command
{
    protected $signature = 'licensing:auto-rotate-keys';
    
    public function handle()
    {
        $activeKey = app(CertificateAuthorityService::class)->getActiveSigningKey();
        
        if (!$activeKey) {
            $this->error('No active signing key found');
            return 1;
        }
        
        // Rotate if key is older than 60 days
        if ($activeKey->created_at->diffInDays() > 60) {
            $this->info('Rotating signing key (60+ days old)');
            
            $this->call('licensing:keys:rotate', [
                '--reason' => 'automatic',
                '--force' => true,
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

# Verify backup integrity
php artisan licensing:health-check --format=json > health-before-backup.json
```

This comprehensive command reference provides all the tools needed for managing a Laravel Licensing installation through the command line, including automation and monitoring capabilities.