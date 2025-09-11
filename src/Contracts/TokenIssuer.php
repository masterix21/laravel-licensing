<?php

namespace LucaLongo\Licensing\Contracts;

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

interface TokenIssuer
{
    public function issue(License $license, LicenseUsage $usage, array $options = []): string;

    public function refresh(string $token, array $options = []): string;
}
