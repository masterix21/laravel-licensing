<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Enums\AuditEventType;

interface AuditLogger
{
    public function log(
        AuditEventType $eventType,
        array $data,
        ?string $actor = null,
        array $context = []
    ): void;
    
    public function query(array $filters = []): iterable;
    
    public function purge(\DateTimeInterface $before): int;
}