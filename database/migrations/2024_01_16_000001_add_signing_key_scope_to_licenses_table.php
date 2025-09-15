<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('signing_scope')->nullable()->after('meta')
                ->comment('Preferred signing key scope for this license');

            $table->index('signing_scope');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex(['signing_scope']);
            $table->dropColumn('signing_scope');
        });
    }
};
