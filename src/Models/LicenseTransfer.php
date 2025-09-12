<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use LucaLongo\Licensing\Enums\TransferStatus;
use LucaLongo\Licensing\Enums\TransferType;

class LicenseTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'license_id',
        'from_licensable_type',
        'from_licensable_id',
        'to_licensable_type',
        'to_licensable_id',
        'transfer_token',
        'transfer_code',
        'status',
        'transfer_type',
        'reason',
        'rejection_reason',
        'initiated_by_type',
        'initiated_by_id',
        'approved_by_type',
        'approved_by_id',
        'rejected_by_type',
        'rejected_by_id',
        'executed_by_type',
        'executed_by_id',
        'requires_source_approval',
        'requires_target_approval',
        'requires_admin_approval',
        'preserve_usages',
        'preserve_expiration',
        'reset_activation',
        'conditions',
        'metadata',
        'source_approved_at',
        'target_approved_at',
        'admin_approved_at',
        'completed_at',
        'cancelled_at',
        'rolled_back_at',
        'expires_at',
    ];

    protected $attributes = [
        'status' => TransferStatus::Pending,
    ];

    protected $casts = [
        'status' => TransferStatus::class,
        'transfer_type' => TransferType::class,
        'requires_source_approval' => 'boolean',
        'requires_target_approval' => 'boolean',
        'requires_admin_approval' => 'boolean',
        'preserve_usages' => 'boolean',
        'preserve_expiration' => 'boolean',
        'reset_activation' => 'boolean',
        'conditions' => 'array',
        'metadata' => 'array',
        'source_approved_at' => 'datetime',
        'target_approved_at' => 'datetime',
        'admin_approved_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $transfer) {
            $transfer->transfer_token ??= Str::random(64);
            $transfer->transfer_code ??= strtoupper(Str::random(12));
            $transfer->expires_at ??= now()->addDays(7);

            $transfer->applyTransferTypeDefaults();
        });
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function fromLicensable(): MorphTo
    {
        return $this->morphTo('from_licensable');
    }

    public function toLicensable(): MorphTo
    {
        return $this->morphTo('to_licensable');
    }

    public function initiatedBy(): MorphTo
    {
        return $this->morphTo('initiated_by');
    }

    public function approvedBy(): MorphTo
    {
        return $this->morphTo('approved_by');
    }

    public function rejectedBy(): MorphTo
    {
        return $this->morphTo('rejected_by');
    }

    public function executedBy(): MorphTo
    {
        return $this->morphTo('executed_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LicenseTransferApproval::class, 'transfer_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(LicenseTransferHistory::class, 'transfer_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', TransferStatus::Pending);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', TransferStatus::Expired)
            ->orWhere(function ($q) {
                $q->where('status', TransferStatus::Pending)
                    ->where('expires_at', '<', now());
            });
    }

    public function scopeReadyForExecution($query)
    {
        return $query->where('status', TransferStatus::Approved)
            ->where(function ($q) {
                $q->where('requires_source_approval', false)
                    ->orWhereNotNull('source_approved_at');
            })
            ->where(function ($q) {
                $q->where('requires_target_approval', false)
                    ->orWhereNotNull('target_approved_at');
            })
            ->where(function ($q) {
                $q->where('requires_admin_approval', false)
                    ->orWhereNotNull('admin_approved_at');
            });
    }

    public function isPending(): bool
    {
        return $this->status === TransferStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === TransferStatus::Approved;
    }

    public function isCompleted(): bool
    {
        return $this->status === TransferStatus::Completed;
    }

    public function isExpired(): bool
    {
        if ($this->status === TransferStatus::Expired) {
            return true;
        }

        return $this->isPending() && $this->expires_at->isPast();
    }

    public function canBeExecuted(): bool
    {
        if ($this->status === TransferStatus::Completed) {
            return false;
        }

        if ($this->status === TransferStatus::Rejected) {
            return false;
        }

        if ($this->status === TransferStatus::Cancelled) {
            return false;
        }

        if ($this->requires_source_approval && ! $this->source_approved_at) {
            return false;
        }

        if ($this->requires_target_approval && ! $this->target_approved_at) {
            return false;
        }

        if ($this->requires_admin_approval && ! $this->admin_approved_at) {
            return false;
        }

        return true;
    }

    public function requiresApprovalFrom(string $type): bool
    {
        return match ($type) {
            'source' => $this->requires_source_approval && ! $this->source_approved_at,
            'target' => $this->requires_target_approval && ! $this->target_approved_at,
            'admin' => $this->requires_admin_approval && ! $this->admin_approved_at,
            default => false,
        };
    }

    public function getCompletionPercentage(): int
    {
        $totalRequired = 0;
        $completed = 0;

        if ($this->requires_source_approval) {
            $totalRequired++;
            if ($this->source_approved_at) {
                $completed++;
            }
        }

        if ($this->requires_target_approval) {
            $totalRequired++;
            if ($this->target_approved_at) {
                $completed++;
            }
        }

        if ($this->requires_admin_approval) {
            $totalRequired++;
            if ($this->admin_approved_at) {
                $completed++;
            }
        }

        if ($totalRequired === 0) {
            return 100;
        }

        return (int) (($completed / $totalRequired) * 100);
    }

    protected function applyTransferTypeDefaults(): void
    {
        if (! $this->transfer_type) {
            return;
        }

        $this->requires_admin_approval = $this->transfer_type->requiresAdminApproval();

        if ($this->transfer_type->requiresApproval()) {
            $this->requires_source_approval = $this->requires_source_approval ?? true;
            $this->requires_target_approval = $this->requires_target_approval ?? true;
        } else {
            $this->requires_source_approval = false;
            $this->requires_target_approval = false;
        }

        if ($this->transfer_type->canPreserveUsages()) {
            $this->preserve_usages = $this->preserve_usages ?? true;
        }
    }

    public function markAsApproved(Model $approver): void
    {
        $this->update([
            'status' => TransferStatus::Approved,
            'approved_by_type' => get_class($approver),
            'approved_by_id' => $approver->getKey(),
        ]);
    }

    public function markAsRejected(Model $rejector, string $reason = null): void
    {
        $this->update([
            'status' => TransferStatus::Rejected,
            'rejected_by_type' => get_class($rejector),
            'rejected_by_id' => $rejector->getKey(),
            'rejection_reason' => $reason,
        ]);
    }

    public function markAsCompleted(Model $executor): void
    {
        $this->update([
            'status' => TransferStatus::Completed,
            'completed_at' => now(),
            'executed_by_type' => get_class($executor),
            'executed_by_id' => $executor->getKey(),
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status' => TransferStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function markAsRolledBack(Model $executor, string $reason = null): void
    {
        $this->update([
            'status' => TransferStatus::RolledBack,
            'rolled_back_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], [
                'rollback_reason' => $reason,
                'rolled_back_by' => get_class($executor).':'.$executor->getKey(),
            ]),
        ]);
    }
}
