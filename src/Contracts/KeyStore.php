<?php

namespace LucaLongo\Licensing\Contracts;

interface KeyStore
{
    public function generate(array $options = []): self;
    
    public function getPublicKey(): string;
    
    public function getPrivateKey(): ?string;
    
    public function getCertificate(): ?string;
    
    public function isActive(): bool;
    
    public function revoke(string $reason, ?\DateTimeInterface $revokedAt = null): self;
    
    public static function findActiveRoot(): ?self;
    
    public static function findActiveSigning(): ?self;
    
    public static function findByKid(string $kid): ?self;
}