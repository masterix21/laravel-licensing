<?php

namespace LucaLongo\Licensing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Models\LicenseTrial;

class TrialExtended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LicenseTrial $trial,
        public int $days,
        public ?string $reason
    ) {}
}
