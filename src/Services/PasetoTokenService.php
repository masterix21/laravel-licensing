<?php

namespace LucaLongo\Licensing\Services;

use DateTimeImmutable;
use LucaLongo\Licensing\Contracts\TokenIssuer;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Keys\AsymmetricSecretKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Rules\IssuedBy;
use ParagonIE\Paseto\Rules\NotExpired;
use ParagonIE\Paseto\Rules\Subject;

class PasetoTokenService implements TokenIssuer, TokenVerifier
{
    public function issue(License $license, LicenseUsage $usage, array $options = []): string
    {
        // Use license's scope if available
        $scope = $license->scope;
        $signingKey = LicensingKey::findActiveSigning($scope);

        // Fallback to global key if no scoped key found
        if (! $signingKey && $scope !== null) {
            $signingKey = LicensingKey::findActiveSigning();
        }

        if (! $signingKey) {
            throw new \RuntimeException($scope
                ? "No active signing key found for scope: {$scope->name}"
                : 'No active signing key found');
        }

        // Get Ed25519 private key for PASETO v4
        $privateKeyBase64 = $signingKey->getPrivateKey();
        if (! $privateKeyBase64) {
            throw new \RuntimeException('Private key not available');
        }

        // Reconstruct PASETO AsymmetricSecretKey from raw bytes
        $privateKey = new AsymmetricSecretKey(base64_decode($privateKeyBase64), new Version4);

        $ttlDays = $options['ttl_days']
            ?? $license->getTokenTtlDays()
            ?? config('licensing.offline_token.ttl_days');

        $issuer = $options['issuer']
            ?? config('licensing.offline_token.issuer', 'laravel-licensing');

        $now = now()->toImmutable();

        // Handle negative TTL (for testing expired tokens)
        $expiration = $ttlDays < 0
            ? $now->subDays(abs($ttlDays))
            : $now->addDays($ttlDays);

        $forceOnlineDays = $license->getForceOnlineAfterDays();
        $forceOnlineAfter = $forceOnlineDays < 0
            ? $now->subDays(abs($forceOnlineDays))
            : $now->addDays($forceOnlineDays);

        $claims = [
            'kid' => $signingKey->kid,
            'license_id' => $license->id,
            'license_key_hash' => $license->key_hash,
            'usage_fingerprint' => $usage->usage_fingerprint,
            'status' => $license->status->value,
            'max_usages' => $license->max_usages,
            'force_online_after' => $forceOnlineAfter->format('c'),
            'licensable_type' => $license->licensable_type,
            'licensable_id' => $license->licensable_id,
        ];

        if ($license->expires_at) {
            $claims['license_expires_at'] = $license->expires_at->format('c');
        }

        if ($license->isInGracePeriod()) {
            $graceDays = $license->getGraceDays();
            $graceUntil = $license->expires_at->addDays($graceDays);
            $claims['grace_until'] = $graceUntil->format('c');
        }

        $token = Builder::getPublic($privateKey, new Version4)
            ->setIssuedAt($now)
            ->setNotBefore($now)
            ->setExpiration($expiration)
            ->setSubject((string) $license->id)
            ->setIssuer($issuer)
            ->setClaims($claims);

        $ca = app(CertificateAuthorityService::class);
        $footer = json_encode([
            'kid' => $signingKey->kid,
            'chain' => $ca->getCertificateChain($signingKey->kid),
        ]);

        return $token->setFooter($footer)->toString();
    }

    public function refresh(string $token, array $options = []): string
    {
        $claims = $this->extractClaims($token);

        $license = License::find($claims['license_id']);
        if (! $license) {
            throw new \RuntimeException('License not found');
        }

        $usage = $license->usages()
            ->where('usage_fingerprint', $claims['usage_fingerprint'])
            ->first();

        if (! $usage) {
            throw new \RuntimeException('Usage not found');
        }

        return $this->issue($license, $usage, $options);
    }

    public function verify(string $token, array $options = []): array
    {
        try {
            $signingKey = $this->resolveSigningKeyFromToken($token);

            if (! $signingKey) {
                throw new \RuntimeException('Signing key not found');
            }

            if ($signingKey->status === \LucaLongo\Licensing\Enums\KeyStatus::Revoked) {
                throw new \RuntimeException('Signing key has been revoked');
            }

            $publicKey = new AsymmetricPublicKey(base64_decode($signingKey->getPublicKey()), new Version4);

            $issuer = $options['issuer']
                ?? config('licensing.offline_token.issuer', 'laravel-licensing');

            $parser = Parser::getPublic($publicKey)
                ->setNonExpiring(true)
                ->addRule(new IssuedBy($issuer));

            if (isset($options['subject'])) {
                $parser->addRule(new Subject((string) $options['subject']));
            }

            $parsed = $parser->parse($token);

            $claims = $parsed->getClaims();
            $footer = json_decode($parsed->getFooter(), true) ?? [];

            $license = isset($claims['license_id'])
                ? License::find($claims['license_id'])
                : null;

            $clockSkew = $this->resolveClockSkew($license);
            $now = now()->timestamp;

            if (isset($claims['exp'])) {
                $exp = new DateTimeImmutable($claims['exp']);
                if ($exp->getTimestamp() < ($now - $clockSkew)) {
                    throw new \RuntimeException('Token verification failed: Token has expired');
                }
            }

            if (isset($claims['nbf'])) {
                $nbf = new DateTimeImmutable($claims['nbf']);
                if ($nbf->getTimestamp() > ($now + $clockSkew)) {
                    throw new \RuntimeException('Token not valid yet');
                }
            }

            if (isset($claims['iat'])) {
                $iat = new DateTimeImmutable($claims['iat']);
                if ($iat->getTimestamp() > ($now + $clockSkew)) {
                    throw new \RuntimeException('Token issued too far in the future');
                }
            }

            if (isset($claims['force_online_after'])) {
                $forceOnlineAfter = new DateTimeImmutable($claims['force_online_after']);
                if ($forceOnlineAfter->getTimestamp() <= ($now - $clockSkew)) {
                    throw new \RuntimeException('Token requires online verification');
                }
            }

            return array_merge($claims, ['footer' => $footer]);
        } catch (\ParagonIE\Paseto\Exception\RuleViolation $e) {
            throw new \RuntimeException('Token verification failed: '.$e->getMessage());
        } catch (\ParagonIE\Paseto\Exception\PasetoException $e) {
            throw new \RuntimeException('Token verification failed: '.$e->getMessage());
        } catch (\Exception $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException('Token verification failed: '.$e->getMessage());
        }
    }

    public function verifyOffline(string $token, string $publicKeyBundle): array
    {
        $bundle = json_decode($publicKeyBundle, true);

        if (! isset($bundle['root']['public_key'])) {
            throw new \RuntimeException('Invalid public key bundle');
        }

        $footer = $this->decodeFooter($token);

        if (! isset($footer['chain'])) {
            throw new \RuntimeException('Token missing certificate chain');
        }

        $ca = app(CertificateAuthorityService::class);

        // Verify that the provided root public key matches the one in the bundle
        if ($bundle['root']['public_key'] !== $footer['chain']['root']['public_key']) {
            throw new \RuntimeException('Root public key mismatch');
        }

        $signingCert = $footer['chain']['signing']['certificate'] ?? null;
        if (! $signingCert || ! $ca->verifyCertificate($signingCert)) {
            throw new \RuntimeException('Invalid signing certificate');
        }

        $signingPublicKey = $footer['chain']['signing']['public_key'];

        try {
            $publicKey = new AsymmetricPublicKey(base64_decode($signingPublicKey), new Version4);
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid signing public key');
        }

        $parser = Parser::getPublic($publicKey)
            ->addRule(new NotExpired);

        try {
            $parsed = $parser->parse($token);

            return $parsed->getClaims();
        } catch (\Exception $e) {
            throw new \RuntimeException('Token verification failed: '.$e->getMessage());
        }
    }

    public function extractClaims(string $token): array
    {
        try {
            $signingKey = $this->resolveSigningKeyFromToken($token) ?? LicensingKey::findActiveSigning();

            if (! $signingKey) {
                throw new \RuntimeException('No active signing key found');
            }

            $publicKey = new AsymmetricPublicKey(base64_decode($signingKey->getPublicKey()), new Version4);
            $parser = Parser::getPublic($publicKey);
            $parsed = $parser->parse($token);

            return $parsed->getClaims();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to extract token claims: '.$e->getMessage());
        }
    }

    protected function resolveSigningKeyFromToken(string $token): ?LicensingKey
    {
        $footer = $this->decodeFooter($token);

        if (isset($footer['kid'])) {
            return LicensingKey::where('kid', $footer['kid'])->first();
        }

        return LicensingKey::findActiveSigning();
    }

    protected function decodeFooter(string $token): array
    {
        try {
            $footer = Parser::extractFooter($token);

            if ($footer === '') {
                return [];
            }

            $decoded = json_decode($footer, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function resolveClockSkew(?License $license): int
    {
        if ($license) {
            return max(0, (int) $license->getClockSkewSeconds());
        }

        return (int) config('licensing.offline_token.clock_skew_seconds', 60);
    }
}
