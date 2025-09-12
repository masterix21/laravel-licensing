<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LucaLongo\Licensing\Enums\TransferStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            
            $table->nullableMorphs('from_licensable');
            $table->nullableMorphs('to_licensable');
            
            $table->string('transfer_token', 64)->unique()->nullable();
            $table->string('transfer_code', 12)->unique()->nullable();
            
            $table->string('status')->default(TransferStatus::Pending->value)->index();
            $table->string('transfer_type')->index();
            
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->nullableMorphs('initiated_by');
            $table->nullableMorphs('approved_by');
            $table->nullableMorphs('rejected_by');
            $table->nullableMorphs('executed_by');
            
            $table->boolean('requires_source_approval')->default(true);
            $table->boolean('requires_target_approval')->default(true);
            $table->boolean('requires_admin_approval')->default(false);
            
            $table->boolean('preserve_usages')->default(false);
            $table->boolean('preserve_expiration')->default(true);
            $table->boolean('reset_activation')->default(false);
            
            $table->json('conditions')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamp('source_approved_at')->nullable();
            $table->timestamp('target_approved_at')->nullable();
            $table->timestamp('admin_approved_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('expires_at')->index();
            
            $table->timestamps();
            
            $table->index(['license_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_transfers');
    }
};