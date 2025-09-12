<?php

namespace LucaLongo\Licensing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Models\LicenseTransfer;

class LicenseTransferRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LicenseTransfer $transfer
    ) {}
}
