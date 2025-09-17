<?php

namespace LucaLongo\Licensing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Models\LicenseTrial;

class TrialStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LicenseTrial $trial
    ) {
    }
}
