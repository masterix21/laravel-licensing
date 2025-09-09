<?php

namespace LucaLongo\Licensing\Models\Traits;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\Licensing\Enums\AuditEventType;

trait HasAuditLog
{
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(
        AuditEventType $eventType,
        array $data,
        ?string $actor = null,
        array $context = []
    ): self {
        $auditableType = null;
        $auditableId = null;

        if (isset($data['model']) && $data['model'] instanceof Model) {
            $model = $data['model'];
            $auditableType = $model->getMorphClass();
            $auditableId = $model->getKey();
            unset($data['model']);
        }

        return static::create([
            'event_type' => $eventType,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'actor' => $actor,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'meta' => array_merge($data, $context),
            'occurred_at' => now(),
        ]);
    }

    #[Scope]
    protected function event(Builder $query, AuditEventType $type): void
    {
        $query->where('event_type', $type);
    }

    #[Scope]
    protected function actor(Builder $query, string $actor): void
    {
        $query->where('actor', $actor);
    }

    #[Scope]
    protected function between(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): void
    {
        $query->whereBetween('occurred_at', [$start, $end]);
    }

    #[Scope]
    protected function before(Builder $query, \DateTimeInterface $date): void
    {
        $query->where('occurred_at', '<', $date);
    }

    #[Scope]
    protected function after(Builder $query, \DateTimeInterface $date): void
    {
        $query->where('occurred_at', '>', $date);
    }

    #[Scope]
    protected function forModel(Builder $query, Model $model): void
    {
        $query->where('auditable_type', $model->getMorphClass())
              ->where('auditable_id', $model->getKey());
    }
}