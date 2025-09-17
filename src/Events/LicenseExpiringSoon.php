<?php

namespace LucaLongo\Licensing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Models\License;

class LicenseExpiringSoon
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public License $license,
        public int $daysRemaining
    ) {
    }
}
