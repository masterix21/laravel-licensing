<?php

namespace LucaLongo\Licensing\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Contracts\UsageRegistrar;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Licensing;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

class LicenseController extends ApiController
{
    public function __construct(
        protected Licensing $licensing,
        protected UsageRegistrar $usageRegistrar,
        protected TokenVerifier $tokenVerifier,
        protected CertificateAuthorityService $certificateAuthority
    ) {
    }

    public function activate(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        if ($license->status->canActivate()) {
            $license->activate();
            $license->refresh();
        }

        if ($response = $this->guardLicenseState($license)) {
            return $response;
        }

        $metadata = $payload['metadata'] ?? [];

        try {
            $usage = $this->licensing->register($license, $payload['fingerprint'], $metadata);
        } catch (\RuntimeException $exception) {
            return $this->mapUsageException($exception);
        }

        $token = null;
        if ($license->isOfflineTokenEnabled()) {
            try {
                $token = $this->licensing->issueToken($license, $usage);
            } catch (\Throwable $exception) {
                return $this->error('TOKEN_ISSUE_FAILED', $exception->getMessage(), 500);
            }
        }

        return $this->success($this->buildLicenseResponse($license->fresh(), $usage, $token));
    }

    public function deactivate(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        $usage = $this->usageRegistrar->findByFingerprint($license, $payload['fingerprint']);

        if (! $usage) {
            return $this->error('FINGERPRINT_NOT_FOUND', 'Fingerprint is not registered for this license', 404);
        }

        $this->licensing->revoke($usage, $payload['reason'] ?? null);

        return $this->success([
            'message' => 'Usage revoked successfully',
            'license' => $this->formatLicense($license->fresh()),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
        ]);

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        if ($response = $this->guardLicenseState($license)) {
            return $response;
        }

        $usage = $this->usageRegistrar->findByFingerprint($license, $payload['fingerprint']);

        if (! $usage || ! $usage->isActive()) {
            return $this->error('FINGERPRINT_MISMATCH', 'Fingerprint does not match an active usage for this license', 403);
        }

        $this->licensing->heartbeat($usage);

        if (! $license->isOfflineTokenEnabled()) {
            return $this->error('OFFLINE_TOKEN_DISABLED', 'Offline tokens are not enabled for this license', 409);
        }

        try {
            $token = $this->licensing->issueToken($license, $usage);
        } catch (\Throwable $exception) {
            return $this->error('TOKEN_REFRESH_FAILED', $exception->getMessage(), 500);
        }

        return $this->success($this->buildLicenseResponse($license->fresh(), $usage->fresh(), $token));
    }

    public function validateLicense(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
        ]);

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        if ($response = $this->guardLicenseState($license)) {
            return $response;
        }

        $usage = $this->usageRegistrar->findByFingerprint($license, $payload['fingerprint']);

        if (! $usage || ! $usage->isActive()) {
            return $this->error('FINGERPRINT_MISMATCH', 'Fingerprint does not match an active usage for this license', 403);
        }

        return $this->success([
            'license' => $this->formatLicense($license),
            'usage' => $this->formatUsage($usage),
        ]);
    }

    public function show(string $licenseKey): JsonResponse
    {
        $license = $this->findLicense($licenseKey);

        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        return $this->success([
            'license' => $this->formatLicense($license, includeUsageSummary: true),
        ]);
    }

    protected function validate(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            return $this->validationErrorResponse($exception);
        }
    }

    /**
     * Laravel's validate() already returns a response on failure; this wrapper keeps return type consistent.
     */
    protected function validationErrorResponse(ValidationException $exception)
    {
        $response = $this->error('VALIDATION_FAILED', 'Request payload is invalid', 422, [
            'details' => $exception->errors(),
        ]);

        throw new ValidationException($exception->validator, $response);
    }

    protected function buildLicenseResponse(License $license, LicenseUsage $usage, ?string $token): array
    {
        $tokenDetails = $token ? $this->extractTokenDetails($token) : [];

        return array_filter([
            'license' => $this->formatLicense($license, includeUsageSummary: true),
            'usage' => $this->formatUsage($usage),
            'token' => $token,
            ...$tokenDetails,
        ], fn ($value) => $value !== null);
    }

    protected function extractTokenDetails(string $token): array
    {
        try {
            $claims = $this->tokenVerifier->extractClaims($token);
        } catch (\Throwable $exception) {
            return [
                'token_error' => $exception->getMessage(),
            ];
        }

        $expiresAt = isset($claims['exp']) ? Carbon::parse($claims['exp']) : null;
        $forceOnlineAfter = isset($claims['force_online_after']) ? Carbon::parse($claims['force_online_after']) : null;

        $refreshAfter = $expiresAt
            ? $expiresAt->copy()->subHours(24)->max(now())
            : null;

        return array_filter([
            'token_expires_at' => $expiresAt?->toIso8601String(),
            'refresh_after' => $refreshAfter?->toIso8601String(),
            'force_online_after' => $forceOnlineAfter?->toIso8601String(),
            'public_key_bundle' => $this->buildPublicKeyBundle(),
        ], fn ($value) => $value !== null);
    }

    protected function buildPublicKeyBundle(): ?array
    {
        $signingKey = LicensingKey::findActiveSigning();
        $rootKey = LicensingKey::findActiveRoot();

        if (! $signingKey || ! $rootKey) {
            return null;
        }

        return [
            'signing' => array_filter([
                'kid' => $signingKey->kid,
                'public_key' => $signingKey->getPublicKey(),
                'certificate' => $signingKey->getCertificate(),
                'valid_from' => $signingKey->valid_from?->format('c'),
                'valid_until' => $signingKey->valid_until?->format('c'),
            ], fn ($value) => $value !== null),
            'root' => array_filter([
                'kid' => $rootKey->kid,
                'public_key' => $this->certificateAuthority->getRootPublicKey(),
                'valid_from' => $rootKey->valid_from?->format('c'),
                'valid_until' => $rootKey->valid_until?->format('c'),
            ], fn ($value) => $value !== null),
            'issued_at' => now()->format('c'),
        ];
    }

    protected function formatLicense(License $license, bool $includeUsageSummary = false): array
    {
        $data = [
            'id' => $license->uid,
            'status' => $license->status->value,
            'activated_at' => $license->activated_at?->toIso8601String(),
            'expires_at' => $license->expires_at?->toIso8601String(),
            'max_usages' => $license->max_usages,
            'features' => $license->getFeatures(),
            'entitlements' => $license->getEntitlements(),
        ];

        if ($license->isInGracePeriod()) {
            $data['grace_days_remaining'] = max(0, $license->getGraceDays() - $license->expires_at->diffInDays(now()));
        }

        if ($includeUsageSummary) {
            $data['active_usages'] = $license->activeUsages()->count();
            $data['available_seats'] = $license->getAvailableSeats();
        }

        return $data;
    }

    protected function formatUsage(LicenseUsage $usage): array
    {
        return [
            'id' => $usage->getKey(),
            'fingerprint' => $usage->usage_fingerprint,
            'status' => $usage->status->value,
            'registered_at' => $usage->registered_at?->toIso8601String(),
            'last_seen_at' => $usage->last_seen_at?->toIso8601String(),
        ];
    }

    protected function findLicense(string $licenseKey): ?License
    {
        return $this->licensing->findByKey($licenseKey);
    }

    protected function guardLicenseState(License $license): ?JsonResponse
    {
        if ($license->status === LicenseStatus::Suspended || $license->status === LicenseStatus::Cancelled) {
            return $this->error('SUSPENDED_LICENSE', 'License is not active', 423);
        }

        if ($license->isExpired() && ! $license->isInGracePeriod()) {
            return $this->error('EXPIRED_LICENSE', 'License is expired', 410);
        }

        if (! $license->isUsable()) {
            return $this->error('LICENSE_NOT_ACTIVE', 'License is not active', 403);
        }

        return null;
    }

    protected function mapUsageException(\RuntimeException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'limit')) {
            return $this->error('USAGE_LIMIT_REACHED', 'License has reached maximum usages', 409);
        }

        if (str_contains(strtolower($message), 'fingerprint')) {
            return $this->error('FINGERPRINT_CONFLICT', $message, 409);
        }

        return $this->error('USAGE_REGISTRATION_FAILED', $message, 400);
    }
}
