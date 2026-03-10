<?php

namespace LucaLongo\Licensing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Models\License;

class UsageLimitReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public License $license,
        public string $fingerprint,
        public array $metadata = []
    ) {}
}
