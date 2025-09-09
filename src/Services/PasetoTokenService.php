<?php

namespace LucaLongo\Licensing\Services;

use DateTimeImmutable;
use DateInterval;
use LucaLongo\Licensing\Contracts\TokenIssuer;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Purpose;
use ParagonIE\Paseto\Protocol\Version2;
use ParagonIE\Paseto\Keys\AsymmetricSecretKey;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Rules\IssuedBy;
use ParagonIE\Paseto\Rules\NotExpired;
use ParagonIE\Paseto\Rules\Subject;
use Spatie\Crypto\Rsa\PrivateKey;
use Spatie\Crypto\Rsa\PublicKey;

class PasetoTokenService implements TokenIssuer, TokenVerifier
{
    public function issue(License $license, LicenseUsage $usage, array $options = []): string
    {
        $signingKey = LicensingKey::findActiveSigning();
        
        if (! $signingKey) {
            throw new \RuntimeException('No active signing key found');
        }

        // PASETO v2 uses RSA, compatible with spatie/crypto
        $privateKeyPem = $signingKey->getPrivateKey();
        if (! $privateKeyPem) {
            throw new \RuntimeException('Private key not available');
        }

        // Convert RSA key to PASETO format
        $privateKey = AsymmetricSecretKey::fromPem($privateKeyPem);

        $ttlDays = $options['ttl_days'] 
            ?? $license->getTokenTtlDays() 
            ?? config('licensing.offline_token.ttl_days');
            
        $issuer = $options['issuer'] 
            ?? config('licensing.offline_token.issuer', 'laravel-licensing');

        $now = new DateTimeImmutable();
        $expiration = $now->add(new DateInterval("P{$ttlDays}D"));
        $forceOnlineAfter = $now->add(new DateInterval("P{$license->getForceOnlineAfterDays()}D"));

        $token = Builder::getPublic($privateKey, new Version2())
            ->setIssuedAt($now)
            ->setNotBefore($now)
            ->setExpiration($expiration)
            ->setSubject((string) $license->id)
            ->setIssuer($issuer)
            ->setKeyId($signingKey->kid)
            ->setClaims([
                'license_id' => $license->id,
                'license_key_hash' => $license->key_hash,
                'usage_fingerprint' => $usage->usage_fingerprint,
                'status' => $license->status->value,
                'max_usages' => $license->max_usages,
                'force_online_after' => $forceOnlineAfter->format('c'),
                'licensable_type' => $license->licensable_type,
                'licensable_id' => $license->licensable_id,
            ]);

        if ($license->expires_at) {
            $token->setClaim('license_expires_at', $license->expires_at->format('c'));
        }

        if ($license->isInGracePeriod()) {
            $graceDays = $license->getGraceDays();
            $graceUntil = $license->expires_at->addDays($graceDays);
            $token->setClaim('grace_until', $graceUntil->format('c'));
        }

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
        $signingKey = LicensingKey::findActiveSigning();
        
        if (! $signingKey) {
            throw new \RuntimeException('No active signing key found');
        }

        $publicKeyPem = $signingKey->getPublicKey();
        $publicKey = AsymmetricPublicKey::fromPem($publicKeyPem);

        $issuer = $options['issuer'] 
            ?? config('licensing.offline_token.issuer', 'laravel-licensing');

        $parser = Parser::getPublic($publicKey, Purpose::public())
            ->addRule(new IssuedBy($issuer))
            ->addRule(new NotExpired());

        if (isset($options['subject'])) {
            $parser->addRule(new Subject($options['subject']));
        }

        $parsed = $parser->parse($token);
        
        $claims = $parsed->getClaims();
        $footer = json_decode($parsed->getFooter(), true);

        $forceOnlineAfter = new DateTimeImmutable($claims['force_online_after']);
        if ($forceOnlineAfter->getTimestamp() < time()) {
            throw new \RuntimeException('Token requires online verification');
        }

        return array_merge($claims, ['footer' => $footer]);
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
        
        $signingCert = $footer['chain']['signing']['certificate'] ?? null;
        if (! $signingCert || ! $ca->verifyCertificate($signingCert)) {
            throw new \RuntimeException('Invalid signing certificate');
        }

        $signingPublicKey = $footer['chain']['signing']['public_key'];
        $publicKey = AsymmetricPublicKey::fromPem($signingPublicKey);

        $parser = Parser::getPublic($publicKey, Purpose::public())
            ->addRule(new NotExpired());

        $parsed = $parser->parse($token);
        
        return $parsed->getClaims();
    }

    public function extractClaims(string $token): array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 4 || $parts[0] !== 'v2' || $parts[1] !== 'public') {
                throw new \RuntimeException('Invalid PASETO v2 public token');
            }

            $payload = json_decode(base64_decode(strtr($parts[2], '-_', '+/')), true);
            
            if (! $payload) {
                throw new \RuntimeException('Failed to decode token payload');
            }

            return $payload;
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to extract token claims: ' . $e->getMessage());
        }
    }
}