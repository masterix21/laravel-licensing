<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\TrialStatus;
use LucaLongo\Licensing\Events\TrialStarted;
use LucaLongo\Licensing\Events\TrialConverted;
use LucaLongo\Licensing\Events\TrialExpired;
use LucaLongo\Licensing\Events\TrialExtended;

class LicenseTrial extends Model
{
    protected $fillable = [
        'license_id',
        'trial_fingerprint',
        'status',
        'started_at',
        'expires_at',
        'converted_at',
        'duration_days',
        'is_extended',
        'extension_days',
        'extension_reason',
        'limitations',
        'feature_restrictions',
        'conversion_trigger',
        'conversion_value',
        'meta',
    ];

    protected $casts = [
        'status' => TrialStatus::class,
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'duration_days' => 'integer',
        'is_extended' => 'boolean',
        'extension_days' => 'integer',
        'limitations' => AsArrayObject::class,
        'feature_restrictions' => 'array',
        'conversion_value' => 'decimal:2',
        'meta' => AsArrayObject::class,
    ];

    protected $attributes = [
        'status' => TrialStatus::Active,
        'is_extended' => false,
        'extension_days' => 0,
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(config('licensing.models.license'));
    }

    public function canConvert(): bool
    {
        return $this->status === TrialStatus::Active;
    }

    public function canExtend(): bool
    {
        return $this->status === TrialStatus::Active && !$this->is_extended;
    }

    public function canCancel(): bool
    {
        return $this->status === TrialStatus::Active;
    }

    public function isActive(): bool
    {
        return $this->status === TrialStatus::Active && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->startOfDay()->lt(now()->startOfDay());
    }

    public function daysRemaining(): int
    {
        if (!$this->expires_at || !$this->isActive()) {
            return 0;
        }

        return max(0, now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false));
    }

    public function start(): self
    {
        if ($this->started_at) {
            throw new \RuntimeException('Trial has already been started');
        }

        $this->update([
            'started_at' => now(),
            'expires_at' => now()->addDays($this->duration_days),
            'status' => TrialStatus::Active,
        ]);

        event(new TrialStarted($this));

        return $this;
    }

    public function extend(int $days, string $reason = null): self
    {
        if (!$this->canExtend()) {
            throw new \RuntimeException('Trial cannot be extended');
        }

        $this->update([
            'expires_at' => $this->expires_at->addDays($days),
            'is_extended' => true,
            'extension_days' => $days,
            'extension_reason' => $reason,
        ]);

        event(new TrialExtended($this, $days, $reason));

        return $this;
    }

    public function convert(string $trigger = null, float $value = null): License
    {
        if (!$this->canConvert()) {
            throw new \RuntimeException('Trial cannot be converted in current status: ' . $this->status->value);
        }

        $this->update([
            'status' => TrialStatus::Converted,
            'converted_at' => now(),
            'conversion_trigger' => $trigger,
            'conversion_value' => $value,
        ]);

        $license = $this->license;
        
        // Only activate if not already active
        if ($license->status !== LicenseStatus::Active) {
            $license->activate();
        }

        event(new TrialConverted($this, $license));

        return $license;
    }

    public function cancel(): self
    {
        if (!$this->canCancel()) {
            throw new \RuntimeException('Trial cannot be cancelled in current status: ' . $this->status->value);
        }

        $this->update([
            'status' => TrialStatus::Cancelled,
        ]);

        return $this;
    }

    public function expire(): self
    {
        if ($this->status !== TrialStatus::Active) {
            return $this;
        }

        $this->update([
            'status' => TrialStatus::Expired,
        ]);

        event(new TrialExpired($this));

        return $this;
    }

    public function hasLimitation(string $key): bool
    {
        return isset($this->limitations[$key]);
    }

    public function getLimitation(string $key, mixed $default = null): mixed
    {
        return $this->limitations[$key] ?? $default;
    }

    public function isFeatureRestricted(string $feature): bool
    {
        return in_array($feature, $this->feature_restrictions ?? [], true);
    }

    public function checkFingerprint(string $fingerprint): bool
    {
        return hash_equals($this->trial_fingerprint, hash('sha256', $fingerprint));
    }

    public static function findByFingerprint(string $fingerprint): ?self
    {
        return static::where('trial_fingerprint', hash('sha256', $fingerprint))->first();
    }

    public static function hasActiveTrialForFingerprint(string $fingerprint): bool
    {
        return static::where('trial_fingerprint', hash('sha256', $fingerprint))
            ->where('status', TrialStatus::Active)
            ->exists();
    }
}