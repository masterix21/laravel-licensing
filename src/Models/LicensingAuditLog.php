<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LucaLongo\Licensing\Contracts\AuditLog;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Models\Traits\HasAuditLog;

/**
 * @property int $id
 * @property AuditEventType $event_type
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor
 * @property string|null $ip
 * @property string|null $user_agent
 * @property ArrayObject|null $meta
 * @property string|null $previous_hash
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LicensingAuditLog extends Model implements AuditLog
{
    use HasAuditLog;

    protected $fillable = [
        'event_type',
        'auditable_type',
        'auditable_id',
        'actor_type',
        'actor_id',
        'actor',
        'ip',
        'user_agent',
        'meta',
        'previous_hash',
        'occurred_at',
    ];

    protected $casts = [
        'event_type' => AuditEventType::class,
        'meta' => AsArrayObject::class,
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->occurred_at ??= now();
        });
    }
}
