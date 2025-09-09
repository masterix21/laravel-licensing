<?php

namespace LucaLongo\Licensing\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LucaLongo\Licensing\Contracts\KeyStore;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\Traits\HasKeyStore;

class LicensingKey extends Model implements KeyStore
{
    use HasKeyStore;

    protected $fillable = [
        'kid',
        'type',
        'status',
        'public_key',
        'private_key_encrypted',
        'certificate',
        'valid_from',
        'valid_until',
        'revoked_at',
        'revocation_reason',
        'meta',
    ];

    protected $casts = [
        'type' => KeyType::class,
        'status' => KeyStatus::class,
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => AsArrayObject::class,
    ];

    protected $attributes = [
        'status' => KeyStatus::Active,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $key) {
            if (! $key->kid) {
                $key->kid = 'kid_' . Str::random(32);
            }
            if (! $key->valid_from) {
                $key->valid_from = now();
            }
        });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', KeyStatus::Active)
              ->where('valid_from', '<=', now())
              ->where(function ($q) {
                  $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());
              });
    }

    #[Scope]
    protected function revoked(Builder $query): void
    {
        $query->where('status', KeyStatus::Revoked);
    }

    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->where('status', KeyStatus::Expired)
              ->orWhere(function ($q) {
                  $q->whereNotNull('valid_until')
                    ->where('valid_until', '<=', now());
              });
    }

    #[Scope]
    protected function ofType(Builder $query, KeyType $type): void
    {
        $query->where('type', $type);
    }
}