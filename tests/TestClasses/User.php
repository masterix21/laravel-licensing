<?php

namespace LucaLongo\Licensing\Tests\TestClasses;

use Illuminate\Foundation\Auth\User as Authenticatable;
use LucaLongo\Licensing\Contracts\CanReceiveLicenseTransfers;
use LucaLongo\Licensing\Contracts\CanInitiateLicenseTransfers;
use LucaLongo\Licensing\Models\License;

class User extends Authenticatable implements CanReceiveLicenseTransfers, CanInitiateLicenseTransfers
{
    protected $fillable = ['name', 'email'];

    protected $table = 'users';

    public $timestamps = true;
    
    public function licenses()
    {
        return $this->morphMany(License::class, 'licensable');
    }
    
    public function canReceiveLicenseTransfers(): bool
    {
        return true;
    }
    
    public function getMaxLicenseLimit(): ?int
    {
        return 10;
    }
    
    public function getActiveLicenseCount(): int
    {
        return $this->licenses()->where('status', 'active')->count();
    }
    
    public function hasReachedLicenseLimit(): bool
    {
        $limit = $this->getMaxLicenseLimit();
        return $limit !== null && $this->getActiveLicenseCount() >= $limit;
    }
    
    public function canInitiateLicenseTransfer(License $license): bool
    {
        return $this->ownsLicense($license);
    }
    
    public function ownsLicense(License $license): bool
    {
        return $license->licensable_type === static::class && 
               $license->licensable_id === $this->id;
    }
    
    public function getLicenseRole(License $license): ?string
    {
        return $this->ownsLicense($license) ? 'owner' : null;
    }
    
    public function hasPermission(string $permission): bool
    {
        return property_exists($this, 'hasPermission') && is_callable($this->hasPermission)
            ? call_user_func($this->hasPermission, $permission)
            : false;
    }
}
