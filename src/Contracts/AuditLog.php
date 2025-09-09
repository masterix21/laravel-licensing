<?php

namespace LucaLongo\Licensing\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\Licensing\Enums\AuditEventType;

interface AuditLog
{
    public function auditable(): MorphTo;
    
    public static function record(
        AuditEventType $eventType,
        array $data,
        ?string $actor = null,
        array $context = []
    ): self;
}