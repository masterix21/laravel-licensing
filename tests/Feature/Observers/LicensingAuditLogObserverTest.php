<?php

use Illuminate\Support\Facades\Config;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Models\LicensingAuditLog;

beforeEach(function () {
    LicensingAuditLog::truncate();
    Config::set('licensing.audit.enabled', true);
    Config::set('licensing.audit.hash_chain', true);
});

test('audit log observer chains hashes when enabled', function () {
    $first = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['example' => 'first'],
    ]);

    $second = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseActivated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['example' => 'second'],
    ]);

    expect($second->previous_hash)->toBe($first->calculateHash())
        ->and($second->verifyChain($first))->toBeTrue();
});

test('audit log observer skips hash chaining when disabled', function () {
    Config::set('licensing.audit.hash_chain', false);

    $first = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['example' => 'first'],
    ]);

    $second = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseActivated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['example' => 'second'],
    ]);

    expect($first->previous_hash)->toBeNull()
        ->and($second->previous_hash)->toBeNull();
});

test('audit logs remain immutable after creation', function () {
    $log = LicensingAuditLog::create([
        'event_type' => AuditEventType::LicenseCreated,
        'auditable_type' => 'App\\Models\\License',
        'auditable_id' => 1,
        'meta' => ['example' => 'immutable'],
    ]);

    expect(fn () => $log->update(['meta' => ['example' => 'mutated']]))
        ->toThrow(RuntimeException::class, 'Audit logs are append-only and cannot be updated');
});
