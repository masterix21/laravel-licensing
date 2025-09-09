<?php

namespace LucaLongo\Licensing\Contracts;

interface CertificateAuthority
{
    public function issueSigningCertificate(
        string $signingPublicKey,
        string $kid,
        \DateTimeInterface $validFrom,
        \DateTimeInterface $validUntil
    ): string;
    
    public function verifyCertificate(string $certificate): bool;
    
    public function getCertificateChain(string $kid): array;
    
    public function getRootPublicKey(): string;
}