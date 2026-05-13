# laravel-licensing — CLI

## Key lifecycle
```bash
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing --kid signing-key-1 [--nbf <ISO> --exp <ISO>]
php artisan licensing:keys:rotate --reason <routine|compromised>
php artisan licensing:keys:revoke <KID> [--at <ISO>]
php artisan licensing:keys:list
php artisan licensing:keys:export --format <jwks|pem|json> [--include-chain]
```
Private keys stored encrypted with the passphrase from `LICENSING_KEY_PASSPHRASE`.

## Offline token issuance
```bash
php artisan licensing:offline:issue \
    --license <id|key> \
    --fingerprint <fp> \
    --ttl 7d
```

## Maintenance
```bash
php artisan licensing:check-expirations         # nightly: state transitions + events
php artisan licensing:cleanup-inactive-usages   # optional auto-revoke
php artisan licensing:notify-expiring           # N, N/2, N/4 days before expiry
```

## Return codes
- `0` success
- `1` invalid args
- `2` not found
- `3` revoked / compromised
- `4` I/O or crypto error

## Rules
- DO set `LICENSING_KEY_PASSPHRASE` before `make-root`. Without it, interactive prompt; in CI use `--no-interaction` with the env set.
- DON'T commit `storage/app/licensing/keys` to git.
- DO export updated public bundle after every rotation and ship to clients.
