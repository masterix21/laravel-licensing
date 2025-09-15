<?php

namespace LucaLongo\Licensing\Services;

use DateInterval;
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

        $now = new DateTimeImmutable;

        // Handle negative TTL (for testing expired tokens)
        if ($ttlDays < 0) {
            $expiration = $now->sub(new DateInterval('P'.abs($ttlDays).'D'));
        } else {
            $expiration = $now->add(new DateInterval("P{$ttlDays}D"));
        }

        $forceOnlineDays = $license->getForceOnlineAfterDays();
        if ($forceOnlineDays < 0) {
            $forceOnlineAfter = $now->sub(new DateInterval('P'.abs($forceOnlineDays).'D'));
        } else {
            $forceOnlineAfter = $now->add(new DateInterval("P{$forceOnlineDays}D"));
        }

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
            // Extract kid from token to find the correct signing key
            $parts = explode('.', $token);
            if (count($parts) >= 4) {
                $footer = json_decode(base64_decode(strtr($parts[3], '-_', '+/')), true);
                if (isset($footer['kid'])) {
                    // Always get fresh from database to check current status
                    $signingKey = LicensingKey::where('kid', $footer['kid'])->first();
                    if (! $signingKey) {
                        throw new \RuntimeException('Signing key not found');
                    }
                    // Check if key has been revoked
                    if ($signingKey->status === \LucaLongo\Licensing\Enums\KeyStatus::Revoked) {
                        throw new \RuntimeException('Signing key has been revoked');
                    }
                }
            }

            if (! isset($signingKey)) {
                $signingKey = LicensingKey::findActiveSigning();
                if (! $signingKey) {
                    throw new \RuntimeException('No active signing key found');
                }
            }

            $publicKeyBase64 = $signingKey->getPublicKey();
            $publicKey = new AsymmetricPublicKey(base64_decode($publicKeyBase64), new Version4);

            $issuer = $options['issuer']
                ?? config('licensing.offline_token.issuer', 'laravel-licensing');

            $clockSkew = config('licensing.offline_token.clock_skew_seconds', 60);

            // Parse token with minimal rules first
            $parser = Parser::getPublic($publicKey);

            try {
                $parsed = $parser->parse($token);
            } catch (\ParagonIE\Paseto\Exception\PasetoException $e) {
                throw new \RuntimeException('Token verification failed: '.$e->getMessage());
            }

            $claims = $parsed->getClaims();
            $footer = json_decode($parsed->getFooter(), true);

            // Now do our custom validation with clock skew tolerance
            $now = now()->timestamp; // Use Laravel's now() which respects time travel

            // Check issuer
            if (! isset($claims['iss']) || $claims['iss'] !== $issuer) {
                throw new \RuntimeException('Token verification failed: Invalid issuer');
            }

            // Check subject if provided
            if (isset($options['subject']) && (! isset($claims['sub']) || $claims['sub'] !== $options['subject'])) {
                throw new \RuntimeException('Token verification failed: Invalid subject');
            }

            // Check expiration
            if (isset($claims['exp'])) {
                $exp = new DateTimeImmutable($claims['exp']);
                if ($exp->getTimestamp() < $now) {
                    throw new \RuntimeException('Token verification failed: Token has expired');
                }
            }

            // Check nbf (not before) with clock skew tolerance
            if (isset($claims['nbf'])) {
                $nbf = new DateTimeImmutable($claims['nbf']);
                $nbfTime = $nbf->getTimestamp();

                // Calculate difference from now
                $diff = $nbfTime - $now;

                // Debug: uncomment to see timing
                // error_log("NBF check: nbf=$nbfTime, now=$now, diff=$diff, clockSkew=$clockSkew");

                // If nbf is in the future beyond clock skew, reject
                if ($diff > $clockSkew) {
                    throw new \RuntimeException('Token not valid yet');
                }
            }

            // Check iat (issued at) with clock skew tolerance
            if (isset($claims['iat'])) {
                $iat = new DateTimeImmutable($claims['iat']);
                if ($iat->getTimestamp() > $now + $clockSkew) {
                    throw new \RuntimeException('Token issued too far in the future');
                }
            }

            // Check force online requirement
            if (isset($claims['force_online_after'])) {
                $forceOnlineAfter = new DateTimeImmutable($claims['force_online_after']);
                if ($forceOnlineAfter->getTimestamp() <= now()->timestamp) {
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

        // Extract footer to get certificate chain
        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            throw new \RuntimeException('Invalid PASETO token format');
        }

        $footer = json_decode(base64_decode(strtr($parts[3], '-_', '+/')), true);

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
            // For extraction without verification, we need to decode the token
            // PASETO v4 uses a different encoding, we need to parse it properly
            $parts = explode('.', $token);
            if (count($parts) < 3 || $parts[0] !== 'v4' || $parts[1] !== 'public') {
                throw new \RuntimeException('Invalid PASETO v4 public token');
            }

            // The payload in v4 is not simply base64 encoded
            // We need to use a different approach - parse without verification
            $signingKey = LicensingKey::findActiveSigning();
            if (! $signingKey) {
                throw new \RuntimeException('No active signing key found');
            }

            $publicKeyBase64 = $signingKey->getPublicKey();
            $publicKey = new AsymmetricPublicKey(base64_decode($publicKeyBase64), new Version4);

            // Parse without strict verification to extract claims
            $parser = Parser::getPublic($publicKey);
            $parsed = $parser->parse($token);

            return $parsed->getClaims();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to extract token claims: '.$e->getMessage());
        }
    }
}
