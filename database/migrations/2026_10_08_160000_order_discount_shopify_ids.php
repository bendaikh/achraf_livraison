<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shopify ids of discounts Flow pushed, so they can be removed with orderEditRemoveDiscount. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('order_discounts', 'shopify_discount_ids')) {
                $table->json('shopify_discount_ids')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            if (Schema::hasColumn('order_discounts', 'shopify_discount_ids')) {
                $table->dropColumn('shopify_discount_ids');
            }
        });
    }
};
