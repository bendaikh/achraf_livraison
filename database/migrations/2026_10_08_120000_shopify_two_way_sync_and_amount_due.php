<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way Shopify sync + amount still due. Additive only: new tables and new nullable columns.
 * Backfills write exclusively to the new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('shopify_shop_id')->nullable()->constrained('shopify_shops')->nullOnDelete();
            $table->string('topic');
            $table->string('webhook_id')->unique();
            $table->string('event_id')->nullable();
            $table->string('resource_id')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['shopify_shop_id', 'topic', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('shopify_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('shopify_shop_id')->nullable()->constrained('shopify_shops')->nullOnDelete();
            $table->string('direction', 8);
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('shopify_id')->nullable();
            $table->string('action')->nullable();
            $table->string('source', 32)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->text('request_excerpt')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'shopify_shop_id', 'created_at']);
            $table->index(['status', 'entity_type']);
        });

        Schema::create('shopify_field_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('shopify_shop_id')->nullable()->constrained('shopify_shops')->nullOnDelete();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source', 16);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sync_log_id')->nullable()->constrained('shopify_sync_logs')->nullOnDelete();
            $table->boolean('conflict')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'entity_type', 'entity_id']);
        });

        Schema::create('order_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_fulfillment_id')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_company')->nullable();
            $table->text('tracking_url')->nullable();
            $table->string('source', 16)->default('shopify');
            $table->timestamp('shopify_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'shopify_fulfillment_id']);
        });

        Schema::table('shopify_shops', function (Blueprint $table) {
            $table->text('granted_scopes')->nullable();
            $table->timestamp('orders_reconciled_at')->nullable();
            $table->string('inventory_location_id')->nullable();
        });

        Schema::table('shopify_app_settings', function (Blueprint $table) {
            $table->text('requested_scopes')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('shopify_sync_status', 20)->nullable();
            $table->text('shopify_sync_error')->nullable();
            $table->timestamp('shopify_synced_at')->nullable();
            $table->unsignedBigInteger('shopify_customer_id')->nullable();
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_due', 12, 2)->nullable();
            $table->decimal('total_outstanding', 12, 2)->nullable();
            $table->json('payment_gateway_names')->nullable();
            $table->text('tags')->nullable();
            $table->json('discount_applications')->nullable();
            $table->json('shopify_refunds')->nullable();
            $table->boolean('amount_due_stale')->default(false);
            $table->index('company_id');
            $table->index('shopify_customer_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('description_html')->nullable();
            $table->text('description_text')->nullable();
            $table->json('options')->nullable();
            $table->string('shopify_sync_status', 20)->nullable();
            $table->text('shopify_sync_error')->nullable();
            $table->timestamp('shopify_synced_at')->nullable();
        });

        $defaultCompany = DB::table('companies')->orderBy('id')->value('id');

        DB::table('shopify_shops')->whereNull('granted_scopes')->whereNotNull('scopes')->update([
            'granted_scopes' => DB::raw('scopes'),
        ]);

        if (Schema::hasTable('orders')) {
            DB::statement('UPDATE orders SET company_id = (SELECT company_id FROM shopify_shops WHERE shopify_shops.id = orders.shopify_shop_id) WHERE company_id IS NULL AND shopify_shop_id IS NOT NULL');
            if ($defaultCompany) {
                DB::table('orders')->whereNull('company_id')->update(['company_id' => $defaultCompany]);
            }
            DB::table('orders')->where('financial_status', 'paid')->update([
                'amount_due' => 0,
                'amount_paid' => DB::raw('total_price'),
                'total_outstanding' => 0,
            ]);
            DB::table('orders')->where(function ($q) {
                $q->whereNull('financial_status')->orWhere('financial_status', '!=', 'paid');
            })->whereNull('amount_due')->update([
                'amount_due' => DB::raw('total_price'),
                'amount_paid' => 0,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillments');
        Schema::dropIfExists('shopify_field_changes');
        Schema::dropIfExists('shopify_sync_logs');
        Schema::dropIfExists('shopify_webhook_events');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'description_html', 'description_text', 'options',
                'shopify_sync_status', 'shopify_sync_error', 'shopify_synced_at',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'shopify_sync_status', 'shopify_sync_error', 'shopify_synced_at', 'shopify_customer_id',
                'amount_paid', 'amount_due', 'total_outstanding', 'payment_gateway_names',
                'tags', 'discount_applications', 'shopify_refunds', 'amount_due_stale',
            ]);
        });

        Schema::table('shopify_app_settings', function (Blueprint $table) {
            $table->dropColumn('requested_scopes');
        });

        Schema::table('shopify_shops', function (Blueprint $table) {
            $table->dropColumn(['granted_scopes', 'orders_reconciled_at', 'inventory_location_id']);
        });
    }
};
