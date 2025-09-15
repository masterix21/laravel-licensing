<?php

namespace LucaLongo\Licensing\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Services\AuditLoggerService;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

class IssueSigningKeyCommand extends Command
{
    protected $signature = 'licensing:keys:issue-signing
                            {--kid= : Key ID for the new signing key}
                            {--scope= : Scope slug or identifier for the signing key}
                            {--nbf= : Not before date (ISO format)}
                            {--exp= : Expiration date (ISO format)}';

    protected $description = 'Issue a new signing keypair signed by the root key';

    public function handle(
        AuditLoggerService $auditLogger,
        CertificateAuthorityService $ca
    ): int {
        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            $this->error('No active root key found. Run licensing:keys:make-root first.');

            return 2;
        }

        $kid = $this->option('kid') ?? 'kid_'.bin2hex(random_bytes(16));

        // Find scope if provided
        $licenseScope = null;
        if ($scopeOption = $this->option('scope')) {
            $licenseScope = LicenseScope::findBySlugOrIdentifier($scopeOption);

            if (!$licenseScope) {
                $this->error("Scope not found: {$scopeOption}");
                $this->info('Available scopes:');
                LicenseScope::active()->each(function ($scope) {
                    $this->line("  - {$scope->slug} ({$scope->name})");
                });
                return 2;
            }
        }

        $validFrom = $this->option('nbf')
            ? new DateTimeImmutable($this->option('nbf'))
            : new DateTimeImmutable;

        $validUntil = $this->option('exp')
            ? new DateTimeImmutable($this->option('exp'))
            : $validFrom->modify('+30 days');

        $scopeInfo = $licenseScope ? " for scope '{$licenseScope->name}'" : ' (global)';
        $this->info("Generating new signing keypair{$scopeInfo}...");

        try {
            $signingKey = new LicensingKey;
            $signingKey->generate([
                'type' => KeyType::Signing,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ]);

            $signingKey->kid = $kid;

            if ($licenseScope) {
                $signingKey->license_scope_id = $licenseScope->id;
            }

            $signingKey->save();

            $certificate = $ca->issueSigningCertificate(
                $signingKey->getPublicKey(),
                $kid,
                $validFrom,
                $validUntil,
                $licenseScope
            );

            $signingKey->update(['certificate' => $certificate]);

            $auditLogger->log(
                AuditEventType::KeySigningIssued,
                [
                    'kid' => $kid,
                    'scope_id' => $licenseScope?->id,
                    'scope_name' => $licenseScope?->name,
                    'valid_from' => $validFrom->format('c'),
                    'valid_until' => $validUntil->format('c'),
                ],
                'console'
            );

            $this->info('Signing key issued successfully!');
            $this->line('');
            $this->line('Key ID: '.$kid);
            if ($licenseScope) {
                $this->line('Scope: '.$licenseScope->name);
                $this->line('Scope Slug: '.$licenseScope->slug);
                $this->line('Scope Identifier: '.$licenseScope->identifier);
            } else {
                $this->line('Scope: Global (no scope specified)');
            }
            $this->line('Valid from: '.$validFrom->format('Y-m-d H:i:s'));
            $this->line('Valid until: '.$validUntil->format('Y-m-d H:i:s'));

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to issue signing key: '.$e->getMessage());

            return 3;
        }
    }
}
