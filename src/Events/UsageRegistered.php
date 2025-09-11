<?php

namespace LucaLongo\Licensing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Models\LicenseUsage;

class UsageRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LicenseUsage $usage
    ) {}
}
