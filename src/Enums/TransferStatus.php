<?php

namespace LucaLongo\Licensing\Enums;

enum TransferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Expired => __('Expired'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
            self::RolledBack => __('Rolled Back'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Expired => 'secondary',
            self::Completed => 'success',
            self::Cancelled => 'secondary',
            self::RolledBack => 'danger',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Expired,
            self::Completed,
            self::Cancelled,
            self::RolledBack,
        ]);
    }

    public function canTransitionTo(self $status): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($status, [self::Approved, self::Rejected, self::Expired, self::Cancelled]),
            self::Approved => in_array($status, [self::Completed, self::Cancelled, self::Expired]),
            default => false,
        };
    }
}
