<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // Remove old signing_scope if it exists
            if (Schema::hasColumn('licenses', 'signing_scope')) {
                $table->dropColumn('signing_scope');
            }

            // Add reference to license_scope
            $table->foreignUlid('license_scope_id')->nullable()
                ->after('template_id')
                ->constrained('license_scopes')
                ->nullOnDelete();

            $table->index('license_scope_id');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign(['license_scope_id']);
            $table->dropColumn('license_scope_id');
        });
    }
};