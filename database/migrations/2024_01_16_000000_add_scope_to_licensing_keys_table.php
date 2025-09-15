<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licensing_keys', function (Blueprint $table) {
            $table->string('scope')->nullable()->after('kid')
                ->comment('Software/application scope for the key (null = global)');

            $table->string('scope_identifier')->nullable()->after('scope')
                ->comment('Unique identifier for the software/application');

            // Add indexes for efficient lookups
            $table->index(['scope', 'status']);
            $table->index(['scope_identifier', 'status']);
            $table->unique(['kid', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('licensing_keys', function (Blueprint $table) {
            $table->dropIndex(['scope', 'status']);
            $table->dropIndex(['scope_identifier', 'status']);
            $table->dropUnique(['kid', 'scope']);

            $table->dropColumn(['scope', 'scope_identifier']);
        });
    }
};
