# laravel-licensing — API & Security

## Endpoints (prefix `/api/licensing/v1`)
| Method | Path                | Middleware                        |
|--------|---------------------|-----------------------------------|
| POST   | /activate           | throttle:licensing-register       |
| POST   | /deactivate         | throttle:licensing-register       |
| POST   | /refresh            | throttle:licensing-token          |
| POST   | /validate           | throttle:licensing-validate       |
| POST   | /heartbeat          | throttle:licensing-validate       |
| POST   | /licenses/show      | throttle:licensing-validate       |
| POST   | /token              | throttle:licensing-token          |
| GET    | /health             | —                                 |

Default limits: `validate_per_minute=60`, `register_per_minute=30`, `token_per_minute=20`.

## Error contract
```json
{ "code": "USAGE_LIMIT_REACHED", "message": "Seat limit reached for this license." }
```
Generic message on 500s. Business codes: `INVALID_KEY`, `USAGE_LIMIT_REACHED`, `LICENSE_EXPIRED`, `LICENSE_SUSPENDED`, `FINGERPRINT_MISMATCH`.

## Health
Returns `{ "status": "ok" | "error" }` per check only. **Never** leak `kid`, validity windows, or exception text.

## DO
- Validate `fingerprint` with `max:255` on every endpoint.
- Resolve services from the container so consumers can override via contracts.
- Use the morph map for `licensable_type`.
- `report()` internal exceptions; return generic 500 message.

## DON'T
- Echo internal exception messages to clients.
- Merge client-supplied data into `meta` root — use `meta.client_data`.
- Log PII (email, IP, full UA) into the audit trail by default.
- Bypass the pessimistic lock in usage registration.

## Audit
Append-only via `config('licensing.audit.store') = 'database' | 'file'`. Consider hash-chained entries for tamper-evidence.
