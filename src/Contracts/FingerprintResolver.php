<?php

namespace LucaLongo\Licensing\Contracts;

use Illuminate\Http\Request;

interface FingerprintResolver
{
    public function resolve(Request $request): string;

    public function generate(array $components): string;

    public function validate(string $fingerprint): bool;
}
