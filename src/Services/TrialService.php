<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\TrialStatus;
use LucaLongo\Licensing\Exceptions\TrialAlreadyExistsException;
use LucaLongo\Licensing\Exceptions\TrialResetAttemptException;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTrial;

class TrialService
{
    public function __construct(
        protected FingerprintResolverService $fingerprintResolver
    ) {}

    public function startTrial(
        License $license,
        string $fingerprint,
        int $durationDays = 14,
        array $limitations = [],
        array $featureRestrictions = []
    ): LicenseTrial {
        return DB::transaction(function () use ($license, $fingerprint, $durationDays, $limitations, $featureRestrictions) {
            $this->checkTrialEligibility($license, $fingerprint);

            $hashedFingerprint = hash('sha256', $fingerprint);

            $trial = $license->trials()->create([
                'trial_fingerprint' => $hashedFingerprint,
                'status' => TrialStatus::Active,
                'started_at' => now(),
                'expires_at' => now()->addDays($durationDays),
                'duration_days' => $durationDays,
                'limitations' => $limitations,
                'feature_restrictions' => $featureRestrictions,
            ]);

            if ($license->status === LicenseStatus::Pending) {
                $license->update(['status' => LicenseStatus::Active]);
            }

            return $trial;
        });
    }

    public function checkTrialEligibility(License $license, string $fingerprint): void
    {
        $hashedFingerprint = hash('sha256', $fingerprint);

        // Check if fingerprint has already been used for a trial on this license
        $existingTrial = $license->trials()
            ->where('trial_fingerprint', $hashedFingerprint)
            ->first();

        if ($existingTrial) {
            throw new TrialAlreadyExistsException(
                "Trial already exists for this fingerprint on license {$license->id}"
            );
        }

        // Check for trial reset attempts (same fingerprint on different licenses)
        if ($this->isTrialResetAttempt($hashedFingerprint)) {
            throw new TrialResetAttemptException(
                'Trial reset attempt detected for fingerprint'
            );
        }
    }

    protected function isTrialResetAttempt(string $hashedFingerprint): bool
    {
        // Check if this fingerprint has been used in any completed/expired trials
        return LicenseTrial::where('trial_fingerprint', $hashedFingerprint)
            ->whereIn('status', [TrialStatus::Expired, TrialStatus::Converted, TrialStatus::Cancelled])
            ->exists();
    }

    public function convertTrial(LicenseTrial $trial, ?string $trigger = null, ?float $value = null): License
    {
        return DB::transaction(function () use ($trial, $trigger, $value) {
            return $trial->convert($trigger, $value);
        });
    }

    public function extendTrial(LicenseTrial $trial, int $days, ?string $reason = null): LicenseTrial
    {
        return DB::transaction(function () use ($trial, $days, $reason) {
            return $trial->extend($days, $reason);
        });
    }

    public function checkExpiredTrials(): int
    {
        $expiredCount = 0;

        LicenseTrial::where('status', TrialStatus::Active)
            ->where('expires_at', '<=', now())
            ->chunk(100, function ($trials) use (&$expiredCount) {
                foreach ($trials as $trial) {
                    $trial->expire();
                    $expiredCount++;
                }
            });

        return $expiredCount;
    }

    public function canAccessFeature(LicenseTrial $trial, string $feature): bool
    {
        if (! $trial->isActive()) {
            return false;
        }

        return ! $trial->isFeatureRestricted($feature);
    }

    public function checkLimitation(LicenseTrial $trial, string $key, mixed $currentValue): bool
    {
        if (! $trial->hasLimitation($key)) {
            return true;
        }

        $limit = $trial->getLimitation($key);

        return $currentValue <= $limit;
    }

    public function getTrialStats(License $license): array
    {
        $trials = $license->trials;

        return [
            'total_trials' => $trials->count(),
            'active_trials' => $trials->where('status', TrialStatus::Active)->count(),
            'converted_trials' => $trials->where('status', TrialStatus::Converted)->count(),
            'expired_trials' => $trials->where('status', TrialStatus::Expired)->count(),
            'cancelled_trials' => $trials->where('status', TrialStatus::Cancelled)->count(),
            'conversion_rate' => $trials->count() > 0
                ? round(($trials->where('status', TrialStatus::Converted)->count() / $trials->count()) * 100, 2)
                : 0,
            'total_conversion_value' => $trials->where('status', TrialStatus::Converted)->sum('conversion_value'),
        ];
    }
}
