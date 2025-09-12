<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_transfer_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('license_transfers')->cascadeOnDelete();
            
            $table->nullableMorphs('approver');
            $table->string('approval_type'); // source, target, admin
            
            $table->string('status'); // approved, rejected
            $table->text('reason')->nullable();
            $table->json('conditions')->nullable();
            
            $table->string('approval_token', 64)->unique()->nullable();
            $table->timestamp('token_expires_at')->nullable();
            
            $table->string('approver_ip', 45)->nullable();
            $table->text('approver_user_agent')->nullable();
            
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            
            $table->index('transfer_id');
            $table->index('approval_type');
            $table->index('status');
            $table->unique(['transfer_id', 'approval_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_transfer_approvals');
    }
};