<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseRenewal extends Model
{
    protected $fillable = [
        'license_id',
        'period_start',
        'period_end',
        'amount_cents',
        'currency',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'amount_cents' => 'integer',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(config('licensing.models.license'));
    }

    public function getDurationInDays(): int
    {
        return $this->period_start->diffInDays($this->period_end);
    }

    public function getFormattedAmount(): ?string
    {
        if ($this->amount_cents === null || $this->currency === null) {
            return null;
        }

        $amount = $this->amount_cents / 100;
        return number_format($amount, 2) . ' ' . strtoupper($this->currency);
    }

    #[Scope]
    protected function inPeriod(Builder $query, \DateTimeInterface $date): void
    {
        $query->where('period_start', '<=', $date)
              ->where('period_end', '>=', $date);
    }

    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where('period_start', '>', now());
    }

    #[Scope]
    protected function past(Builder $query): void
    {
        $query->where('period_end', '<', now());
    }
}