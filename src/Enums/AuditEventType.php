<?php

namespace LucaLongo\Licensing\Enums;

enum AuditEventType: string
{
    // License events
    case LicenseCreated = 'license.created';
    case LicenseActivated = 'license.activated';
    case LicenseExpired = 'license.expired';
    case LicenseRenewed = 'license.renewed';
    case LicenseSuspended = 'license.suspended';
    case LicenseCancelled = 'license.cancelled';
    case LicenseExtended = 'license.extended';
    
    // Usage events
    case UsageRegistered = 'usage.registered';
    case UsageHeartbeat = 'usage.heartbeat';
    case UsageRevoked = 'usage.revoked';
    case UsageReplaced = 'usage.replaced';
    case UsageLimitReached = 'usage.limit_reached';
    
    // Key events
    case KeyRootGenerated = 'key.root_generated';
    case KeySigningIssued = 'key.signing_issued';
    case KeyRotated = 'key.rotated';
    case KeyRevoked = 'key.revoked';
    case KeyExported = 'key.exported';
    
    // Token events
    case TokenIssued = 'token.issued';
    case TokenRefreshed = 'token.refreshed';
    case TokenVerificationFailed = 'token.verification_failed';
    
    // API events
    case ApiRateLimitExceeded = 'api.rate_limit_exceeded';
    case ApiUnauthorizedAccess = 'api.unauthorized_access';
}