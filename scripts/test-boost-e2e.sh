#!/usr/bin/env bash
#
# End-to-end smoke test for the Laravel Boost integration.
# Creates a throwaway Laravel app under .sandbox/boost-test, installs this
# package via a path repository, runs `boost:install` + `boost:update`, and
# greps the generated CLAUDE.md for every expected section heading.
#
# Idempotent: removes and recreates .sandbox/boost-test on each run.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SANDBOX="$REPO_ROOT/.sandbox/boost-test"

EXPECTED_SECTIONS=(
    "# laravel-licensing — Core"
    "# laravel-licensing — Licenses"
    "# laravel-licensing — Usages (Seats)"
    "# laravel-licensing — Scopes & Templates"
    "# laravel-licensing — Trials"
    "# laravel-licensing — Offline Tokens"
    "# laravel-licensing — CLI"
    "# laravel-licensing — API & Security"
)

echo "==> Resetting sandbox at $SANDBOX"
rm -rf "$SANDBOX"
mkdir -p "$(dirname "$SANDBOX")"

echo "==> Scaffolding Laravel app"
composer create-project laravel/laravel "$SANDBOX" --no-interaction --quiet

cd "$SANDBOX"

echo "==> Wiring path repository to local package"
composer config repositories.local path "$REPO_ROOT"
composer require "masterix21/laravel-licensing:@dev" --no-interaction --quiet

echo "==> Installing laravel/boost"
composer require laravel/boost --dev --no-interaction --quiet

echo "==> Running boost:install"
php artisan boost:install --guidelines --no-interaction >/dev/null

echo "==> Persisting agents + selected package in boost.json (boost:install --no-interaction does not persist either)"
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$config = app(Laravel\Boost\Support\Config::class);
\$config->setAgents(['claude_code']);
\$config->setPackages(['masterix21/laravel-licensing']);
"

echo "==> Running boost:update"
php artisan boost:update --no-interaction --ignore-skills >/dev/null

echo "==> Verifying CLAUDE.md contains every expected section"
FAILED=0
for section in "${EXPECTED_SECTIONS[@]}"; do
    if grep -qF "$section" CLAUDE.md; then
        echo "  ✓ $section"
    else
        echo "  ✗ MISSING: $section"
        FAILED=1
    fi
done

if [ "$FAILED" -ne 0 ]; then
    echo "==> FAIL: some sections missing from CLAUDE.md"
    exit 1
fi

echo "==> PASS: Boost e2e green"
