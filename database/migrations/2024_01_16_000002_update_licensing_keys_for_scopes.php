<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensing_keys', function (Blueprint $table) {
            // Remove old scope columns if they exist
            if (Schema::hasColumn('licensing_keys', 'scope')) {
                $table->dropIndex(['scope', 'status']);
                $table->dropColumn('scope');
            }

            if (Schema::hasColumn('licensing_keys', 'scope_identifier')) {
                $table->dropIndex(['scope_identifier', 'status']);
                $table->dropColumn('scope_identifier');
            }

            // Add reference to license_scope
            $table->foreignUlid('license_scope_id')->nullable()
                ->after('type')
                ->constrained('license_scopes')
                ->nullOnDelete()
                ->comment('Null means global key');

            $table->index(['license_scope_id', 'status']);
            $table->unique(['kid', 'license_scope_id']);
        });
    }

    public function down(): void
    {
        Schema::table('licensing_keys', function (Blueprint $table) {
            $table->dropForeign(['license_scope_id']);
            $table->dropIndex(['license_scope_id', 'status']);
            $table->dropUnique(['kid', 'license_scope_id']);
            $table->dropColumn('license_scope_id');
        });
    }
};
