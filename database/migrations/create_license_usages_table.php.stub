<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucaLongo\Licensing\Enums\UsageStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->string('usage_fingerprint');
            $table->string('status', 20)->default(UsageStatus::Active->value)->index();
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('client_type')->nullable();
            $table->string('name')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            
            $table->unique(['license_id', 'usage_fingerprint']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_usages');
    }
};