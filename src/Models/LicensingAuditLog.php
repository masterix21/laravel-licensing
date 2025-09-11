<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Contracts\AuditLog;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Models\Traits\HasAuditLog;

class LicensingAuditLog extends Model implements AuditLog
{
    use HasAuditLog;

    protected $fillable = [
        'event_type',
        'auditable_type',
        'auditable_id',
        'actor_type',
        'actor_id',
        'meta',
        'previous_hash',
    ];

    protected $casts = [
        'event_type' => AuditEventType::class,
        'meta' => AsArrayObject::class,
    ];
}