<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Contracts\AuditLogger;
use LucaLongo\Licensing\Enums\AuditEventType;

class AuditLoggerService implements AuditLogger
{
    protected string $modelClass;

    public function __construct()
    {
        $this->modelClass = config('licensing.models.audit_log');
    }

    public function log(
        AuditEventType $eventType,
        array $data,
        ?string $actor = null,
        array $context = []
    ): void {
        if (! config('licensing.audit.enabled')) {
            return;
        }

        $auditableType = null;
        $auditableId = null;

        if (isset($data['model']) && $data['model'] instanceof Model) {
            $model = $data['model'];
            $auditableType = $model->getMorphClass();
            $auditableId = $model->getKey();
            unset($data['model']);
        }

        $this->modelClass::create([
            'event_type' => $eventType,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'actor' => $actor ?? $this->resolveActor(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'meta' => array_merge($data, $context),
            'occurred_at' => now(),
        ]);
    }

    public function query(array $filters = []): iterable
    {
        $query = $this->modelClass::query();

        if (isset($filters['event_type'])) {
            $query->event($filters['event_type']);
        }

        if (isset($filters['actor'])) {
            $query->actor($filters['actor']);
        }

        if (isset($filters['from'])) {
            $query->after($filters['from']);
        }

        if (isset($filters['to'])) {
            $query->before($filters['to']);
        }

        if (isset($filters['model'])) {
            $query->forModel($filters['model']);
        }

        return $query->orderBy('occurred_at', 'desc')->cursor();
    }

    public function purge(\DateTimeInterface $before): int
    {
        if (! config('licensing.audit.enabled')) {
            return 0;
        }

        return $this->modelClass::before($before)->delete();
    }

    protected function resolveActor(): ?string
    {
        if (auth()->check()) {
            $user = auth()->user();
            return get_class($user) . ':' . $user->getKey();
        }

        if (app()->runningInConsole()) {
            return 'console:' . get_current_user();
        }

        return null;
    }
}