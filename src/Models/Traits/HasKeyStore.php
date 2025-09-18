<?php

namespace LucaLongo\Licensing\Models\Traits;

use Illuminate\Support\Str;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\KeyType;
use ParagonIE\Paseto\Keys\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;

trait HasKeyStore
{
    protected static ?string $cachedPassphrase = null;

    public function generate(array $options = []): self
    {
        $type = $options['type'] ?? KeyType::Signing;

        // Generate Ed25519 key pair for PASETO v4
        $secretKey = AsymmetricSecretKey::generate(new Version4);
        $publicKey = $secretKey->getPublicKey();

        // Use existing kid if set, otherwise generate new one
        $this->kid = $this->kid ?? 'kid_'.Str::random(32);
        $this->type = $type;
        $this->algorithm = 'Ed25519';
        $this->public_key = base64_encode($publicKey->raw());
        $this->private_key_encrypted = $this->encryptPrivateKey(base64_encode($secretKey->raw()));
        $this->valid_from = $this->valid_from ?? ($options['valid_from'] ?? now());
        $this->valid_until = $this->valid_until ?? ($options['valid_until'] ?? null);
        $this->status = KeyStatus::Active;

        if ($type === KeyType::Signing && ! $this->valid_until) {
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

    protected function encryptPrivateKey(string $privateKey): string
    {
        $passphrase = $this->resolvePassphrase();

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = hash('sha256', $passphrase, true);
        $encrypted = sodium_crypto_secretbox($privateKey, $nonce, $key);

        return base64_encode($nonce.$encrypted);
    }

    protected function decryptPrivateKey(string $encryptedKey): string
    {
        $passphrase = $this->resolvePassphrase();

        $decoded = base64_decode($encryptedKey);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = hash('sha256', $passphrase, true);

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt private key');
        }

        return $decrypted;
    }

    protected function resolvePassphrase(): string
    {
        if (static::$cachedPassphrase !== null) {
            return static::$cachedPassphrase;
        }

        $config = config('licensing.crypto.keystore');

        $passphrase = $config['passphrase'] ?? null;

        if (! $passphrase && isset($config['passphrase_env'])) {
            // Try multiple methods to get the environment variable
            $envKey = $config['passphrase_env'];
            $passphrase = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey) ?: null;
        }

        if (! $passphrase) {
            throw new \RuntimeException('Key passphrase not configured');
        }

        static::$cachedPassphrase = $passphrase;

        return static::$cachedPassphrase;
    }

    public static function cachePassphrase(string $passphrase): void
    {
        static::$cachedPassphrase = $passphrase;
    }

    public static function forgetCachedPassphrase(): void
    {
        static::$cachedPassphrase = null;
    }
}
