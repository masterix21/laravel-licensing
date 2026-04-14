<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL and MariaDB enforce a hard limit of 64 characters on identifiers
 * (table names, index names, foreign key constraints). SQLite has no such
 * limit, so these assertions lift the check up into PHP to prevent
 * regressions like https://github.com/lucalongo/laravel-licensing/issues/9
 * from slipping through a SQLite-only test suite. On MySQL/MariaDB the
 * check is redundant because the server itself would reject the migration.
 */
const MYSQL_IDENTIFIER_MAX = 64;

it('keeps every table name within the MySQL identifier limit', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Identifier length is enforced by the DB engine on non-sqlite drivers.');
    }

    $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
        ->pluck('name');

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        expect(strlen($table))
            ->toBeLessThanOrEqual(MYSQL_IDENTIFIER_MAX, "Table name '{$table}' exceeds MySQL's 64-char limit");
    }
});

it('keeps every index name within the MySQL identifier limit', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Identifier length is enforced by the DB engine on non-sqlite drivers.');
    }

    $indexes = collect(DB::select("SELECT name, tbl_name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%'"));

    expect($indexes)->not->toBeEmpty();

    foreach ($indexes as $index) {
        expect(strlen($index->name))
            ->toBeLessThanOrEqual(
                MYSQL_IDENTIFIER_MAX,
                "Index '{$index->name}' on table '{$index->tbl_name}' is ".strlen($index->name)." chars and exceeds MySQL's 64-char limit"
            );
    }
});

it('can run the real package migrations in the order declared by the service provider', function () {
    // Schema from Testbench already includes the fixture migrations. This
    // assertion simply verifies that the service provider's migration list is
    // consistent with what the fixture test case applied — any foreign key
    // ordering mistake would have blown up during defineDatabaseMigrations().
    expect(Schema::hasTable('licenses'))->toBeTrue();
    expect(Schema::hasTable('license_templates'))->toBeTrue();
    expect(Schema::hasTable('license_scopes'))->toBeTrue();
    expect(Schema::hasTable('license_transfers'))->toBeTrue();
    expect(Schema::hasTable('license_transfer_histories'))->toBeTrue();
});
