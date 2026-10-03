<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T4 — Shopify product catalog (Shopify stays the source of truth) + internal order line editing.
 * Additive only: new tables, new nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each company connects its own Shopify store(s).
        if (! Schema::hasColumn('shopify_shops', 'company_id')) {
            Schema::table('shopify_shops', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                $table->timestamp('catalog_synced_at')->nullable()->after('last_synced_at');
                $table->text('catalog_sync_error')->nullable()->after('catalog_synced_at');
            });
            $companyId = DB::table('companies')->orderBy('id')->value('id');
            if ($companyId) {
                DB::table('shopify_shops')->whereNull('company_id')->update(['company_id' => $companyId]);
            }
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('shopify_shop_id')->nullable()->constrained('shopify_shops')->nullOnDelete();
            $table->string('source', 20)->default('shopify');
            $table->unsignedBigInteger('shopify_product_id')->nullable();
            $table->string('title');
            $table->string('handle')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status', 20)->default('active'); // active | draft | archived
            $table->text('image_url')->nullable();           // Shopify CDN URL (referenced, not downloaded)
            $table->json('images')->nullable();
            $table->json('collections')->nullable();          // collection titles
            $table->text('tags')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('deleted_in_shopify_at')->nullable();
            $table->timestamps();
            $table->unique(['shopify_shop_id', 'shopify_product_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_variant_id')->nullable();
            $table->unsignedBigInteger('shopify_inventory_item_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->integer('inventory_quantity')->nullable();
            $table->json('inventory_levels')->nullable(); // {location_id: available}
            $table->boolean('inventory_tracked')->default(false);
            $table->string('inventory_policy', 20)->default('deny'); // deny | continue (Shopify)
            $table->text('image_url')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'shopify_variant_id']);
            $table->index(['company_id', 'sku']);
            $table->index('shopify_variant_id');
            $table->index('shopify_inventory_item_id');
        });

        // Internal (Lav'Fast Flow) edits of the order lines — never pushed to Shopify automatically.
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'items_edited_at')) {
                $table->timestamp('items_edited_at')->nullable();
                $table->foreignId('items_edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('shopify_line_items')->nullable(); // lines as last received from Shopify
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('items_edited_by');
            $table->dropColumn(['items_edited_at', 'shopify_line_items']);
        });
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::table('shopify_shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['catalog_synced_at', 'catalog_sync_error']);
        });
    }
};
