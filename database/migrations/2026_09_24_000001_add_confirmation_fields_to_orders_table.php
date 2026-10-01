<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('confirmation_status')->default('to_confirm')->after('status');
            $table->text('internal_note')->nullable()->after('note');
            $table->decimal('shipping_price', 12, 2)->nullable()->after('total_price');
            $table->json('confirmation_history')->nullable()->after('internal_note');
            $table->foreignId('confirmed_by')->nullable()->after('confirmation_history')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->foreignId('confirmation_acted_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmation_acted_at')->nullable()->after('confirmation_acted_by');
            $table->timestamp('postponed_until')->nullable()->after('confirmation_acted_at');
            $table->string('cancellation_reason')->nullable()->after('postponed_until');

            $table->index('confirmation_status');
            $table->index('postponed_until');
        });

        $receivedAt = now()->toIso8601String();

        DB::table('orders')->orderBy('id')->chunkById(100, function ($orders) use ($receivedAt) {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update([
                    'confirmation_status' => 'to_confirm',
                    'confirmation_history' => json_encode([
                        [
                            'type' => 'received',
                            'label' => 'Commande reçue depuis Shopify',
                            'user_id' => null,
                            'user_name' => null,
                            'at' => $order->shopify_created_at
                                ? date('c', strtotime($order->shopify_created_at))
                                : $receivedAt,
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('confirmation_acted_by');
            $table->dropIndex(['confirmation_status']);
            $table->dropIndex(['postponed_until']);
            $table->dropColumn([
                'confirmation_status',
                'internal_note',
                'shipping_price',
                'confirmation_history',
                'confirmed_at',
                'confirmation_acted_at',
                'postponed_until',
                'cancellation_reason',
            ]);
        });
    }
};
