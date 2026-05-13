# laravel-licensing — Trials

## Start a trial
```php
use LucaLongo\Licensing\Services\TrialService;

// TrialService::startTrial() requires a License to attach the trial to.
// Create or retrieve the license first, then start the trial.
$trial = app(TrialService::class)->startTrial(
    license: $license,
    fingerprint: $fingerprint,
    durationDays: 14,
);
```

## Fingerprint hashing
- Stored as **HMAC-SHA256** with `config('app.key')` as the key.
- Legacy SHA256 fallback for backward compatibility.
- Same fingerprint stable across trial → conversion to keep attribution.

## Conversion
```php
// convert() issues a License from the trial's existing license template,
// marks the trial as Converted, and fires TrialConverted.
$license = $trial->convert(trigger: 'checkout', value: 99.0);
```
The trial row is preserved (audit), `converted_at` is set.

## Rules
- DON'T reuse the same fingerprint across scopes unless you explicitly want shared trial limits.
- DO check `LicenseTrial::hasActiveTrialForFingerprint($fingerprint)` before offering a new trial.
- DON'T store raw fingerprints anywhere — only HMAC.
