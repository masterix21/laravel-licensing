<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucaLongo\Licensing\Enums\KeyStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licensing_keys', function (Blueprint $table) {
            $table->id();
            $table->string('kid');
            $table->string('type', 20);
            $table->foreignId('license_scope_id')->nullable()
                ->constrained('license_scopes')
                ->nullOnDelete()
                ->comment('Null means global key');
            $table->string('status', 20)->default(KeyStatus::Active->value)->index();
            $table->string('algorithm', 20)->default('Ed25519');
            $table->text('public_key');
            $table->text('private_key_encrypted')->nullable();
            $table->text('certificate')->nullable();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revocation_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['valid_from', 'valid_until']);
            $table->index(['license_scope_id', 'status']);
            $table->unique(['kid', 'license_scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licensing_keys');
    }
};