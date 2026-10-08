<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — create / cancel / soft-delete. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            $table->foreignId('commercial_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('creation_key', 64)->nullable()->unique()->after('commercial_user_id');
            $table->string('flow_state', 20)->nullable()->after('creation_key')->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 80)->nullable();
            $table->text('cancel_comment')->nullable();
            $table->json('extra_fees')->nullable();
            $table->string('discount_kind', 16)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->text('flow_notice')->nullable();
        });

        Schema::create('order_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deletions');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('commercial_user_id');
            $table->dropUnique(['creation_key']);
            $table->dropColumn([
                'creation_key', 'flow_state', 'deleted_at', 'cancelled_at', 'cancel_reason', 'cancel_comment',
                'extra_fees', 'discount_kind', 'discount_value', 'flow_notice',
            ]);
            $table->dropConstrainedForeignId('cancelled_by');
        });
    }
};
