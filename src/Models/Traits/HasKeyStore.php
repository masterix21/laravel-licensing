<?php

namespace LucaLongo\Licensing\Models\Traits;

use Illuminate\Support\Str;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\KeyType;
use Spatie\Crypto\Rsa\KeyPair;
use Spatie\Crypto\Rsa\PrivateKey;

trait HasKeyStore
{
    public function generate(array $options = []): self
    {
        $type = $options['type'] ?? KeyType::Signing;
        
        $keyPair = KeyPair::generate();
        
        $this->kid = 'kid_' . Str::random(32);
        $this->type = $type;
        $this->public_key = $keyPair->publicKey()->toString();
        $this->private_key_encrypted = $this->encryptPrivateKey($keyPair->privateKey()->toString());
        $this->valid_from = $options['valid_from'] ?? now();
        $this->valid_until = $options['valid_until'] ?? null;
        
        if ($type === KeyType::Signing) {
            $this->valid_until = $this->valid_from->addDays(30);
        }
        
        $this->save();
        
        return $this;
    }

    public function getPublicKey(): string
    {
        return $this->public_key;
    }

    public function getPrivateKey(): ?string
    {
        if (! $this->private_key_encrypted) {
            return null;
        }
        
        return $this->decryptPrivateKey($this->private_key_encrypted);
    }

    public function getCertificate(): ?string
    {
        return $this->certificate;
    }

    public function isActive(): bool
    {
        if ($this->status !== KeyStatus::Active) {
            return false;
        }
        
        $now = now();
        
        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }
        
        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }
        
        return true;
    }

    public function revoke(string $reason, ?\DateTimeInterface $revokedAt = null): self
    {
        $this->update([
            'status' => KeyStatus::Revoked,
            'revoked_at' => $revokedAt ?? now(),
            'revocation_reason' => $reason,
        ]);
        
        return $this;
    }

    public static function findActiveRoot(): ?self
    {
        return static::where('type', KeyType::Root)
                     ->where('status', KeyStatus::Active)
                     ->where(function ($query) {
                         $query->whereNull('valid_until')
                               ->orWhere('valid_until', '>', now());
                     })
                     ->where('valid_from', '<=', now())
                     ->first();
    }

    public static function findActiveSigning(): ?self
    {
        return static::where('type', KeyType::Signing)
                     ->where('status', KeyStatus::Active)
                     ->where('valid_until', '>', now())
                     ->where('valid_from', '<=', now())
                     ->orderBy('created_at', 'desc')
                     ->first();
    }

    public static function findByKid(string $kid): ?self
    {
        return static::where('kid', $kid)->first();
    }

    protected function encryptPrivateKey(string $privateKey): string
    {
        $passphrase = env(config('licensing.crypto.keystore.passphrase_env'));
        
        if (! $passphrase) {
            throw new \RuntimeException('Key passphrase not configured');
        }
        
        return PrivateKey::fromString($privateKey)
            ->encrypt($passphrase)
            ->toString();
    }

    protected function decryptPrivateKey(string $encryptedKey): string
    {
        $passphrase = env(config('licensing.crypto.keystore.passphrase_env'));
        
        if (! $passphrase) {
            throw new \RuntimeException('Key passphrase not configured');
        }
        
        return PrivateKey::fromString($encryptedKey)
            ->decrypt($passphrase)
            ->toString();
    }
}