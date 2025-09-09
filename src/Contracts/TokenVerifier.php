<?php

namespace LucaLongo\Licensing\Contracts;

interface TokenVerifier
{
    public function verify(string $token, array $options = []): array;
    
    public function verifyOffline(string $token, string $publicKeyBundle): array;
    
    public function extractClaims(string $token): array;
}