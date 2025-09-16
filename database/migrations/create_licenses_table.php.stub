<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucaLongo\Licensing\Enums\LicenseStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique()->index()->comment('Public unique identifier for API usage');
            $table->string('key_hash')->unique();
            $table->string('status', 20)->default(LicenseStatus::Pending->value)->index();
            $table->nullableMorphs('licensable');
            $table->foreignId('template_id')->nullable()->constrained('license_templates')->nullOnDelete();
            $table->foreignId('license_scope_id')->nullable()
                ->constrained('license_scopes')
                ->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('max_usages')->default(1);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('template_id');
            $table->index('license_scope_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
