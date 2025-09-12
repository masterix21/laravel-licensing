<?php

namespace LucaLongo\Licensing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LucaLongo\Licensing\Services\TrialService;

class CheckExpiredTrialsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TrialService $trialService): void
    {
        $expiredCount = $trialService->checkExpiredTrials();

        if ($expiredCount > 0) {
            info("Expired {$expiredCount} trials");
        }
    }
}
