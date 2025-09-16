# License Transfers

License transfers allow changing license ownership between different entities (users, organizations, projects). This feature supports various transfer scenarios including permanent ownership changes, temporary assignments, and delegated access.

## Table of Contents

- [Overview](#overview)
- [Transfer Types](#transfer-types)
- [Transfer Process](#transfer-process)
- [Approval Workflows](#approval-workflows)
- [Transfer Validation](#transfer-validation)
- [Implementation Examples](#implementation-examples)
- [Security Considerations](#security-considerations)

## Overview

The transfer system provides controlled mechanisms for changing license ownership while maintaining audit trails and enforcing business rules.

```php
// Initiate transfer
$transfer = $license->initiateTransfer([
    'recipient_type' => User::class,
    'recipient_id' => $newOwner->id,
    'type' => TransferType::Ownership,
    'reason' => 'Account merger',
]);

// Approve and complete
$transfer->approve($approver);
$transfer->complete();
```

## Transfer Types

### Ownership Transfer
Permanent change of license ownership.

### Temporary Transfer
Time-limited assignment with automatic reversion.

### Delegation
Shared access without ownership change.

## Transfer Process

1. **Initiation**: Current owner initiates transfer
2. **Validation**: System validates transfer requirements
3. **Approval**: Required approvals are obtained
4. **Completion**: License ownership is changed
5. **Audit**: Transfer is logged for compliance

## Implementation Examples

### Basic Transfer

```php
class LicenseTransferController
{
    public function initiate(Request $request, License $license)
    {
        $transfer = app(LicenseTransferService::class)
            ->initiateTransfer($license, $request->validated());
            
        return response()->json([
            'transfer_id' => $transfer->id,
            'status' => $transfer->status->value,
            'requires_approval' => $transfer->requiresApproval(),
        ]);
    }
}
```

### Approval Workflow

```php
class TransferApprovalService
{
    public function processApproval(LicenseTransfer $transfer, Model $approver): void
    {
        if (!$this->canApprove($transfer, $approver)) {
            throw new UnauthorizedApprovalException();
        }
        
        $transfer->approve($approver);
        
        if ($transfer->hasAllRequiredApprovals()) {
            $transfer->complete();
        }
    }
}
```

This transfer system ensures secure and auditable license ownership changes with proper validation and approval workflows.

## Transfer Validation

`TransferValidationService` applies layered safeguards before a transfer is even created:

- **Cooling period** – prevents rapid ownership flips by enforcing the configurable `cooling_period_days`
- **Transfer type matching** – verifies the requested `TransferType` matches the source/target entity pairing (user → org, org → user, etc.)
- **Capability checks** – calls into `CanReceiveLicenseTransfers` to ensure the recipient is eligible and within its own limits
- **Suspicious pattern detection** – flags frequent transfers, ping-pong swaps, or high-value moves based on template metadata

All thresholds are driven by `config('licensing.transfer')`, making it easy to adjust behaviour per deployment or environment.

## Security Considerations

- Track configuration changes alongside release notes—altering cooling periods or thresholds can impact downstream workflows
- Surface flagged transfers to operations teams when `suspicious_pattern_requires_review` is enabled
- Combine audit logs with transfer history to provide a full trail whenever ownership changes hands
