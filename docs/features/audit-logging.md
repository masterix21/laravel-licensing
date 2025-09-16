# Audit Logging

Comprehensive audit logging provides tamper-evident trails of all licensing operations for compliance, security monitoring, and troubleshooting.

## Table of Contents

- [Overview](#overview)
- [Event Types](#event-types)
- [Log Structure](#log-structure)
- [Tamper Evidence](#tamper-evidence)
- [Configuration](#configuration)
- [Querying Logs](#querying-logs)
- [Compliance](#compliance)

## Overview

The audit system automatically logs all significant licensing operations with cryptographic integrity protection.

```php
// Logs are created automatically
$license->activate(); // Creates LicenseActivated audit log

// Manual logging
app(AuditLogger::class)->log(
    AuditEventType::CustomEvent,
    $license,
    ['custom_data' => 'value'],
    $user
);
```

## Event Types

Tracked events include:
- License lifecycle (create, activate, renew, expire)
- Usage management (register, revoke, replace)
- Key operations (generate, rotate, revoke)
- Transfers and approvals
- Security events

## Log Structure

Each audit entry contains:

```php
[
    'event_type' => 'license_activated',
    'auditable_type' => 'LucaLongo\\Licensing\\Models\\License',
    'auditable_id' => '01HZQM5...',
    'actor' => 'App\\Models\\User:123',
    'actor_type' => 'App\\Models\\User',
    'actor_id' => '123',
    'ip' => '203.0.113.42',
    'user_agent' => 'MyApp/1.0.0 (macOS)',
    'meta' => [
        'activation_method' => 'api',
        'plan' => 'enterprise',
    ],
    'previous_hash' => 'sha256-of-previous-entry',
    'occurred_at' => '2024-01-15T10:00:00Z',
    'created_at' => '2024-01-15T10:00:00Z'
]
```

## Tamper Evidence

Hash chaining provides tamper detection:

```php
class AuditLogVerifier
{
    public function verifyIntegrity(): bool
    {
        $logs = LicensingAuditLog::orderBy('id')->get();

        foreach ($logs as $index => $log) {
            $previous = $index === 0 ? null : $logs[$index - 1];

            if (! $log->verifyChain($previous)) {
                return false; // Tampering detected
            }
        }
        
        return true;
    }
}
```

## Configuration

```php
// config/licensing.php
return [
    'audit' => [
        'enabled' => true,
        'store' => 'database', // database, file, remote
        'retention_days' => 2555, // 7 years
        'hash_chain' => true,
        'sensitive_data_masking' => true,
    ],
];
```

## Querying Logs

```php
// Get logs for specific entity
$licenseLogs = app(AuditLogger::class)->getLogsFor($license);

// Get logs by event type
$activations = app(AuditLogger::class)
    ->getLogsByType(AuditEventType::LicenseActivated);

// Complex queries
$suspiciousActivity = LicensingAuditLog::where('ip', $suspiciousIp)
    ->whereIn('event_type', ['license_created', 'usage_registered'])
    ->where('created_at', '>', now()->subHour())
    ->get();
```

The audit logging system provides comprehensive compliance and security monitoring with cryptographic integrity protection for all licensing operations.
