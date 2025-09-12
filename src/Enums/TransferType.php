<?php

namespace LucaLongo\Licensing\Enums;

enum TransferType: string
{
    case UserToUser = 'user_to_user';
    case UserToOrg = 'user_to_org';
    case OrgToUser = 'org_to_user';
    case OrgToOrg = 'org_to_org';
    case Recovery = 'recovery';
    case Migration = 'migration';

    public function label(): string
    {
        return match ($this) {
            self::UserToUser => __('User to User'),
            self::UserToOrg => __('User to Organization'),
            self::OrgToUser => __('Organization to User'),
            self::OrgToOrg => __('Organization to Organization'),
            self::Recovery => __('Recovery'),
            self::Migration => __('Migration'),
        };
    }

    public function requiresApproval(): bool
    {
        return match ($this) {
            self::Recovery, self::Migration => false,
            default => true,
        };
    }

    public function requiresAdminApproval(): bool
    {
        return match ($this) {
            self::OrgToOrg, self::Recovery, self::Migration => true,
            default => false,
        };
    }

    public function canPreserveUsages(): bool
    {
        return match ($this) {
            self::UserToOrg, self::Migration => true,
            default => false,
        };
    }
}
