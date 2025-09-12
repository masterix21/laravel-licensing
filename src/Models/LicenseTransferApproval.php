<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use LucaLongo\Licensing\Enums\ApprovalStatus;

class LicenseTransferApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id',
        'approver_type',
        'approver_id',
        'approval_type',
        'status',
        'reason',
        'conditions',
        'approval_token',
        'token_expires_at',
        'approver_ip',
        'approver_user_agent',
        'approved_at',
        'rejected_at',
    ];

    protected $attributes = [
        'status' => ApprovalStatus::Pending,
    ];

    protected $casts = [
        'status' => ApprovalStatus::class,
        'conditions' => 'array',
        'token_expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $approval) {
            if (! $approval->approval_token) {
                $approval->approval_token = Str::random(64);
            }

            if (! $approval->token_expires_at) {
                $approval->token_expires_at = now()->addDays(3);
            }
        });
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(LicenseTransfer::class, 'transfer_id');
    }

    public function approver(): MorphTo
    {
        return $this->morphTo('approver');
    }

    public function scopePending($query)
    {
        return $query->whereNull('approved_at')
            ->whereNull('rejected_at');
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopeRejected($query)
    {
        return $query->whereNotNull('rejected_at');
    }

    public function scopeExpired($query)
    {
        return $query->pending()
            ->where('token_expires_at', '<', now());
    }

    public function isPending(): bool
    {
        return ! $this->approved_at && ! $this->rejected_at;
    }

    public function isApproved(): bool
    {
        return ! is_null($this->approved_at);
    }

    public function isRejected(): bool
    {
        return ! is_null($this->rejected_at);
    }

    public function isExpired(): bool
    {
        if (! $this->isPending()) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    public function approve(Model $approver, string $reason = null, array $conditions = null): void
    {
        if (! $this->isPending()) {
            throw new \RuntimeException('Cannot approve a non-pending approval');
        }

        $this->update([
            'approver_type' => get_class($approver),
            'approver_id' => $approver->getKey(),
            'status' => ApprovalStatus::Approved,
            'reason' => $reason,
            'conditions' => $conditions,
            'approved_at' => now(),
            'approver_ip' => request()->ip(),
            'approver_user_agent' => request()->userAgent(),
        ]);

        $this->updateTransferApprovalTimestamp();
    }

    public function reject(Model $rejector, string $reason = null): void
    {
        if (! $this->isPending()) {
            throw new \RuntimeException('Cannot reject a non-pending approval');
        }

        $this->update([
            'approver_type' => get_class($rejector),
            'approver_id' => $rejector->getKey(),
            'status' => ApprovalStatus::Rejected,
            'reason' => $reason,
            'rejected_at' => now(),
            'approver_ip' => request()->ip(),
            'approver_user_agent' => request()->userAgent(),
        ]);

        $this->transfer->markAsRejected($rejector, $reason);
    }

    protected function updateTransferApprovalTimestamp(): void
    {
        $transfer = $this->transfer;

        match ($this->approval_type) {
            'source' => $transfer->update(['source_approved_at' => now()]),
            'target' => $transfer->update(['target_approved_at' => now()]),
            'admin' => $transfer->update(['admin_approved_at' => now()]),
            default => null,
        };

        if ($transfer->canBeExecuted()) {
            $transfer->markAsApproved($this->approver);
        }
    }

    public function validateToken(string $token): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        return hash_equals($this->approval_token, $token);
    }
}