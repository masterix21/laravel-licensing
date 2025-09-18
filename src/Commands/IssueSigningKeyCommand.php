<?php

namespace LucaLongo\Licensing\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use LucaLongo\Licensing\Enums\AuditEventType;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Models\LicenseScope;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\AuditLoggerService;
use LucaLongo\Licensing\Services\CertificateAuthorityService;

class IssueSigningKeyCommand extends Command
{
    protected $signature = 'licensing:keys:issue-signing '
        .'{--kid= : Key ID for the new signing key} '
        .'{--scope= : Scope slug or identifier for the signing key} '
        .'{--days= : Validity window in days} '
        .'{--nbf= : Not before date (ISO format)} '
        .'{--exp= : Expiration date (ISO format)}';

    protected $description = 'Issue a new signing key signed by the active root key';

    public function handle(
        AuditLoggerService $auditLogger,
        CertificateAuthorityService $ca
    ): int {
        $rootKey = LicensingKey::findActiveRoot();

        if (! $rootKey) {
            $this->line('No active root key found. Run licensing:keys:make-root first.');

            return self::FAILURE;
        }

        $kid = $this->option('kid') ?? 'signing-'.uniqid();

        $licenseScope = null;
        if ($scopeOption = $this->option('scope')) {
            $licenseScope = LicenseScope::findBySlugOrIdentifier($scopeOption);

            if (! $licenseScope) {
                $this->line("Scope not found: {$scopeOption}");
                $this->line('Available scopes:');
                LicenseScope::active()->each(function ($scope) {
                    $this->line("  - {$scope->slug} ({$scope->name})");
                });

                return 2;
            }
        }

        $validFrom = $this->option('nbf')
            ? new DateTimeImmutable($this->option('nbf'))
            : new DateTimeImmutable;

        $validUntil = null;
        $validForDays = 30;

        if ($this->option('days') !== null) {
            $daysOption = $this->option('days');

            if (! is_numeric($daysOption) || (int) $daysOption <= 0) {
                $this->line('The --days option must be a positive integer.');

                return self::FAILURE;
            }

            $validForDays = (int) $daysOption;
            $validUntil = $validFrom->modify("+{$validForDays} days");
        } elseif ($this->option('exp')) {
            $validUntil = new DateTimeImmutable($this->option('exp'));
            $validForDays = max(1, $validUntil->diff($validFrom)->days);
        } else {
            $validUntil = $validFrom->modify('+30 days');
        }

        $this->line('Generating signing key pair...');
        if ($this->output->isVerbose()) {
            $this->line('Generating RSA key pair');
        }

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

            if ($this->output->isVerbose()) {
                $this->line('Creating certificate');
                $this->line('Signing certificate with root key');
            }

            $certificate = $ca->issueSigningCertificate(
                $signingKey->getPublicKey(),
                $kid,
                $validFrom,
                $validUntil,
                $licenseScope
            );

            if ($this->output->isVerbose()) {
                $this->line('Storing key in keystore');
            }

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

            $this->line('Signing key issued successfully');
            $this->line('Key ID: '.$kid);
            if ($licenseScope) {
                $this->line('Scope: '.$licenseScope->name.' ('.$licenseScope->slug.')');
            } else {
                $this->line('Scope: Global');
            }
            $this->line('Valid for: '.$validForDays.' days');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->line('Failed to issue signing key: '.$e->getMessage());

            return 3;
        }
    }
}
