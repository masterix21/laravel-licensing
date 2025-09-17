<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('license_templates', 'supports_trial')) {
                $table->boolean('supports_trial')->nullable();
            }

            if (! Schema::hasColumn('license_templates', 'trial_duration_days')) {
                $table->unsignedInteger('trial_duration_days')->nullable();
            }

            if (! Schema::hasColumn('license_templates', 'has_grace_period')) {
                $table->boolean('has_grace_period')->nullable();
            }

            if (! Schema::hasColumn('license_templates', 'grace_period_days')) {
                $table->unsignedInteger('grace_period_days')->nullable();
            }

            if (! Schema::hasColumn('license_templates', 'license_duration_days')) {
                $table->unsignedInteger('license_duration_days')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('license_templates', function (Blueprint $table) {
            $columns = [
                'supports_trial',
                'trial_duration_days',
                'has_grace_period',
                'grace_period_days',
                'license_duration_days',
            ];

            $drop = array_filter($columns, fn ($column) => Schema::hasColumn('license_templates', $column));

            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
