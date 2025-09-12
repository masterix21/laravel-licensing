<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Contracts\CanInitiateLicenseTransfers;
use LucaLongo\Licensing\Models\LicenseTransfer;
use LucaLongo\Licensing\Models\LicenseTransferApproval;

class TransferApprovalService
{
    public function determineRequiredApprovals(LicenseTransfer $transfer): array
    {
        $approvals = [];

        if ($transfer->requires_source_approval) {
            $approvals['source'] = [
                'required' => true,
                'approver_type' => $transfer->from_licensable_type,
                'approver_id' => $transfer->from_licensable_id,
                'timeout_hours' => 72,
            ];
        }

        if ($transfer->requires_target_approval) {
            $approvals['target'] = [
                'required' => true,
                'approver_type' => $transfer->to_licensable_type,
                'approver_id' => $transfer->to_licensable_id,
                'timeout_hours' => 72,
            ];
        }

        if ($transfer->requires_admin_approval) {
            $approvals['admin'] = [
                'required' => true,
                'approver_type' => null,
                'approver_id' => null,
                'timeout_hours' => 120,
            ];
        }

        return $approvals;
    }

    public function canApprove(LicenseTransferApproval $approval, Model $approver): bool
    {
        if ($approval->isApproved() || $approval->isRejected()) {
            return false;
        }

        return match ($approval->approval_type) {
            'source' => $this->canApproveAsSource($approval, $approver),
            'target' => $this->canApproveAsTarget($approval, $approver),
            'admin' => $this->canApproveAsAdmin($approver),
            default => false,
        };
    }

    public function canReject(LicenseTransferApproval $approval, Model $rejector): bool
    {
        return $this->canApprove($approval, $rejector);
    }

    protected function canApproveAsSource(LicenseTransferApproval $approval, Model $approver): bool
    {
        $transfer = $approval->transfer;

        if (get_class($approver) !== $transfer->from_licensable_type) {
            return false;
        }

        if ($approver->getKey() !== $transfer->from_licensable_id) {
            return false;
        }

        if ($approver instanceof CanInitiateLicenseTransfers) {
            return $approver->ownsLicense($transfer->license);
        }

        return true;
    }

    protected function canApproveAsTarget(LicenseTransferApproval $approval, Model $approver): bool
    {
        $transfer = $approval->transfer;

        if (get_class($approver) !== $transfer->to_licensable_type) {
            return false;
        }

        if ($approver->getKey() !== $transfer->to_licensable_id) {
            return false;
        }

        return true;
    }

    protected function canApproveAsAdmin(Model $approver): bool
    {
        if (!method_exists($approver, 'hasPermission')) {
            return false;
        }

        return $approver->hasPermission('approve-license-transfers');
    }

    public function createApproval(
        LicenseTransfer $transfer,
        string $approvalType,
        ?Model $approver = null
    ): LicenseTransferApproval {
        return LicenseTransferApproval::create([
            'transfer_id' => $transfer->id,
            'approval_type' => $approvalType,
            'approver_type' => $approver ? get_class($approver) : null,
            'approver_id' => $approver?->getKey(),
        ]);
    }
}