<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_shop_id')->constrained('shopify_shops')->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('order_number')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('financial_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('line_items')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['shopify_shop_id', 'shopify_order_id']);
            $table->index('status');
            $table->index('order_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
