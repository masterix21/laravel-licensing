# Repository Guidelines

## Project Structure & Module Organization
Domain code lives in `src/`, split by role (contracts, models, services, events, HTTP controllers/middleware). API endpoints ship from `routes/api.php` and respect config-driven prefixes. Package config sits in `config/licensing.php`; publishable migrations/factories in `database/`. Blade views for onboarding stay in `resources/views`. Docs and rollout playbooks live in `docs/`; log new features under `docs/features`. Tests in `tests/Unit` and `tests/Feature` mirror production namespaces, with fixtures/helpers in `tests/Helpers`. CI artifacts land in `build/`.

## Build, Test, and Development Commands
Run `composer install` after cloning or updating dependencies. Execute `composer test` (Pest) for the suite and `composer test-coverage` when checking coverage reports (writes to `build/report.junit.xml`). Use `composer analyse` to run Larastan (phpstan level 5, temp dir `build/phpstan`). Format via `composer format` (Laravel Pint). When adjusting package discovery, rerun `composer prepare` to refresh the Testbench bootstrap.

## Coding Style & Naming Conventions
Target PHP 8.3 features, follow PSR-12 with four-space indentation, and group imports alphabetically. Use expressive aggregate names (`LicenseScopeRepository`, `TokenIssuer`) and snake_case config keys. Keep enums/value objects inside `src/Enums` and contracts alongside their consumption path. Run Pint before opening a PR to fix spacing, trailing commas, and docblock alignment. New console commands adopt the `licensing:` prefix (e.g., `licensing:keys:rotate`); HTTP routes follow kebab-case segments.

## Testing Guidelines
Prefer Pest `it()` descriptions that read like behavior specs. Unit tests stay in `tests/Unit`; integration flows belong in `tests/Feature`. Reuse factories in `database/factories` and helpers in `tests/Helpers` for complex fixtures. Tests execute in random order (see `phpunit.xml.dist`), so set up/teardown state defensively. Name files `*Test.php` to match the subject class or command. Cover critical scenarios: expiration windows, seat exhaustion, key rotation, offline token validation. Use `composer test-coverage` before release branches to catch regressions.

## Commit & Pull Request Guidelines
Write commit subjects in present tense (“Add scope assignment audit”) and keep changes focused. Reference issues with GitHub keywords when applicable. PRs should outline the impact, list verification commands, and attach screenshots for CLI/API output. Flag reviewers whenever migrations, route prefixes, or configuration defaults change so downstream apps can prepare.

## Security & Configuration Tips
Never commit generated keys, certificates, or `.env` assets; document setup steps in `docs/installation.md` instead. When tuning cryptographic services in `config/licensing.php`, explain rotation plans in the PR and coordinate rollout dates. Validate root and signing key commands (`php artisan licensing:keys:make-root`, `licensing:keys:issue-signing`) in staging before tagging a release, and update docs if key formats shift.
