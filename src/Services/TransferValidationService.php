<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Contracts\CanReceiveLicenseTransfers;
use LucaLongo\Licensing\Enums\TransferType;
use LucaLongo\Licensing\Exceptions\TransferValidationException;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTransferApproval;

class TransferValidationService
{
    public function validateTransferEligibility(
        License $license,
        Model $targetEntity,
        TransferType $transferType
    ): void {
        if (!$license->isTransferable()) {
            throw new TransferValidationException('License is not transferable in its current state');
        }

        $this->validateCoolingPeriod($license);
        $this->validateTransferType($license, $targetEntity, $transferType);
        
        if ($targetEntity instanceof CanReceiveLicenseTransfers) {
            $this->validateTargetEntity($targetEntity);
        }
        
        $this->detectSuspiciousPatterns($license, $targetEntity);
    }

    protected function validateCoolingPeriod(License $license): void
    {
        $coolingDays = config('licensing.transfer.cooling_period_days', 30);
        
        if ($coolingDays <= 0) {
            return;
        }

        $lastTransfer = $license->transfers()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if (!$lastTransfer) {
            return;
        }

        $daysSinceLastTransfer = $lastTransfer->completed_at->diffInDays(now());
        
        if ($daysSinceLastTransfer < $coolingDays) {
            throw new TransferValidationException(
                "Transfer cooling period not met. Please wait {$coolingDays} days between transfers."
            );
        }
    }

    protected function validateTransferType(
        License $license,
        Model $targetEntity,
        TransferType $transferType
    ): void {
        $sourceType = class_basename($license->licensable_type);
        $targetType = class_basename($targetEntity::class);
        
        $expectedType = match ([$sourceType, $targetType]) {
            ['User', 'User'] => TransferType::UserToUser,
            ['User', 'Organization'] => TransferType::UserToOrg,
            ['Organization', 'User'] => TransferType::OrgToUser,
            ['Organization', 'Organization'] => TransferType::OrgToOrg,
            default => null,
        };

        if ($expectedType && $transferType !== $expectedType && 
            !in_array($transferType, [TransferType::Recovery, TransferType::Migration])) {
            throw new TransferValidationException(
                "Invalid transfer type. Expected {$expectedType->value}, got {$transferType->value}"
            );
        }
    }

    protected function validateTargetEntity(CanReceiveLicenseTransfers $targetEntity): void
    {
        if (!$targetEntity->canReceiveLicenseTransfers()) {
            throw new TransferValidationException(
                'Target entity cannot receive license transfers at this time'
            );
        }

        if ($targetEntity->hasReachedLicenseLimit()) {
            throw new TransferValidationException(
                'Target entity has reached its license limit'
            );
        }
    }

    protected function detectSuspiciousPatterns(License $license, Model $targetEntity): void
    {
        $patterns = [];
        
        if ($this->detectFrequentTransfers($license)) {
            $patterns[] = 'frequent_transfers';
        }

        if ($this->detectPingPongPattern($license, $targetEntity)) {
            $patterns[] = 'ping_pong_transfer';
        }

        if ($this->detectUnusualValueTransfer($license)) {
            $patterns[] = 'high_value_transfer';
        }

        if (!empty($patterns)) {
            $requiresReview = config('licensing.transfer.suspicious_pattern_requires_review', true);
            
            if ($requiresReview) {
                throw new TransferValidationException(
                    'Transfer blocked due to suspicious patterns'
                );
            }
        }
    }

    protected function detectFrequentTransfers(License $license): bool
    {
        $recentTransfers = $license->transfers()
            ->where('created_at', '>', now()->subDays(90))
            ->count();

        return $recentTransfers > 3;
    }

    protected function detectPingPongPattern(License $license, Model $targetEntity): bool
    {
        $lastTransfer = $license->transfers()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        if (!$lastTransfer) {
            return false;
        }

        return $lastTransfer->from_licensable_type === get_class($targetEntity) &&
               $lastTransfer->from_licensable_id === $targetEntity->getKey();
    }

    protected function detectUnusualValueTransfer(License $license): bool
    {
        if (!$license->template) {
            return false;
        }

        $value = $license->template->getMetadata('estimated_value');
        
        if (!$value) {
            return false;
        }

        $highValueThreshold = config('licensing.transfer.high_value_threshold', 10000);
        
        return $value > $highValueThreshold;
    }

    public function validateApprovalToken(string $token, LicenseTransferApproval $approval): bool
    {
        if ($approval->isExpired()) {
            return false;
        }

        return $approval->validateToken($token);
    }
}