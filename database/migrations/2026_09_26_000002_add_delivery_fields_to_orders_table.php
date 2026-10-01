<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_status')->nullable()->after('confirmation_status');
            $table->foreignId('driver_id')->nullable()->after('delivery_status')->constrained('drivers')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->timestamp('delivery_taken_at')->nullable()->after('assigned_at');
            $table->timestamp('delivery_postponed_until')->nullable()->after('delivery_taken_at');
            $table->string('delivery_failure_reason')->nullable()->after('delivery_postponed_until');
            $table->decimal('amount_collected', 12, 2)->nullable()->after('delivery_failure_reason');
            $table->timestamp('delivered_at')->nullable()->after('amount_collected');
            $table->timestamp('cod_remitted_at')->nullable()->after('delivered_at');

            $table->index('delivery_status');
            $table->index(['confirmation_status', 'delivery_status']);
            $table->index(['driver_id', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['confirmation_status', 'delivery_status']);
            $table->dropIndex(['driver_id', 'delivery_status']);
            $table->dropConstrainedForeignId('driver_id');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn([
                'delivery_status',
                'assigned_at',
                'delivery_taken_at',
                'delivery_postponed_until',
                'delivery_failure_reason',
                'amount_collected',
                'delivered_at',
                'cod_remitted_at',
            ]);
        });
    }
};
