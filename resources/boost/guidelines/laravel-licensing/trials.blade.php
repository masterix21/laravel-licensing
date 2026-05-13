# laravel-licensing — Trials

## Start a trial
```php
use LucaLongo\Licensing\Models\LicenseTrial;

$trial = LicenseTrial::start(
    licensable: $user,
    fingerprint: $fingerprint,
    durationDays: 14,
    scope: $scope,                            // optional
);
```

## Fingerprint hashing
- Stored as **HMAC-SHA256** with `config('app.key')` as the key.
- Legacy SHA256 fallback for backward compatibility.
- Same fingerprint stable across trial → conversion to keep attribution.

## Conversion
```php
$license = $trial->convertTo($template);      // links trial to issued license
```
The trial row is preserved (audit), `converted_at` is set.

## Rules
- DON'T reuse the same fingerprint across scopes unless you explicitly want shared trial limits.
- DO check `LicenseTrial::hasActiveTrial($fingerprint, $scope)` before offering a new trial.
- DON'T store raw fingerprints anywhere — only HMAC.
