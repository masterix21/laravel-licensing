<?php

namespace LucaLongo\Licensing\Services;

use Illuminate\Http\Request;
use LucaLongo\Licensing\Contracts\FingerprintResolver;

class FingerprintResolverService implements FingerprintResolver
{
    public function resolve(Request $request): string
    {
        $components = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'accept_language' => $request->header('Accept-Language'),
            'accept_encoding' => $request->header('Accept-Encoding'),
        ];

        if ($request->has('hardware_id')) {
            $components['hardware_id'] = $request->input('hardware_id');
        }

        if ($request->has('machine_id')) {
            $components['machine_id'] = $request->input('machine_id');
        }

        return $this->generate($components);
    }

    public function generate(array $components): string
    {
        ksort($components);
        
        $normalized = array_filter($components, function ($value) {
            return $value !== null && $value !== '';
        });

        $serialized = json_encode($normalized);
        
        return hash('sha256', $serialized);
    }

    public function validate(string $fingerprint): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $fingerprint) === 1;
    }
}