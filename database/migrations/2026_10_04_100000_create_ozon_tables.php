<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T14 — Ozon Express carrier (Intégrations → Transporteurs → Ozon Express).
 * Additive only: new tables, nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('customer_id', 64)->nullable();
            $table->text('api_key')->nullable(); // encrypted cast, never sent to the browser
            // parcel-stock: 1 = stock chez Ozon, 0 = ramassage. Default + per parcel type override.
            $table->unsignedTinyInteger('default_stock')->default(0);
            $table->json('stock_by_type')->nullable(); // {"livraison": null|0|1, "echange": null|0|1}
            $table->boolean('default_open')->default(true);   // parcel-open 1 (oui) / 2 (non)
            $table->boolean('default_fragile')->default(false);
            $table->string('nature_mode', 20)->default('products'); // products | fixed | none
            $table->string('nature_text', 120)->nullable();
            $table->boolean('send_products')->default(true); // products JSON (SKU lines only)
            $table->boolean('send_note')->default(true);
            $table->json('status_mapping')->nullable(); // raw Ozon status => delivery_statuses.code|null
            $table->json('seen_statuses')->nullable();  // raw statuses received (for the mapping table)
            $table->boolean('auto_sync')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_message', 500)->nullable();
            $table->timestamps();
        });

        // Official Ozon city list (GET https://api.ozonexpress.ma/cities, public, same for everyone).
        Schema::create('ozon_cities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('ozon_id')->unique();
            $table->string('ref', 20)->nullable();
            $table->string('name', 120);
            $table->string('name_key', 120)->index(); // normalised name for matching
            $table->decimal('delivered_price', 10, 2)->nullable();
            $table->decimal('returned_price', 10, 2)->nullable();
            $table->decimal('refused_price', 10, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Order city (as typed in Shopify) => Ozon city id, per company. auto | manual.
        Schema::create('ozon_city_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('city_key', 160);
            $table->string('city_label', 160);
            $table->unsignedInteger('ozon_city_id')->nullable();
            $table->string('source', 10)->default('auto'); // auto | manual
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'city_key']);
        });

        Schema::create('ozon_delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ref', 80)->nullable()->index();
            $table->string('state', 20)->default('creating'); // creating | created | filled | saved | failed
            $table->unsignedInteger('parcels_count')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->json('responses')->nullable(); // raw (redacted) responses of the 3 steps
            $table->timestamp('saved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ozon_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sav_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('livraison'); // livraison | echange
            $table->string('tracking_number', 80)->nullable()->index();
            $table->string('state', 20)->default('created'); // created | delivered | returned | cancelled
            // Parcel as returned by Ozon (add-parcel / parcel-info)
            $table->string('receiver')->nullable();
            $table->string('phone', 40)->nullable();
            $table->unsignedInteger('city_id')->nullable();
            $table->string('city_name', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('delivered_price', 10, 2)->nullable();
            $table->decimal('returned_price', 10, 2)->nullable();
            $table->decimal('refused_price', 10, 2)->nullable();
            // Options sent
            $table->unsignedTinyInteger('parcel_stock')->nullable();
            $table->unsignedTinyInteger('parcel_open')->nullable();
            $table->unsignedTinyInteger('parcel_fragile')->nullable();
            $table->unsignedTinyInteger('parcel_replace')->nullable();
            // Tracking (raw Ozon status kept as is)
            $table->string('raw_status', 120)->nullable();
            $table->string('raw_status_comment', 500)->nullable();
            $table->timestamp('status_at')->nullable();
            $table->string('mapped_status', 60)->nullable();
            $table->json('history')->nullable();
            $table->foreignId('delivery_note_id')->nullable()->constrained('ozon_delivery_notes')->nullOnDelete();
            $table->json('request_payload')->nullable(); // form fields sent (never contains the key)
            $table->json('create_response')->nullable();
            $table->json('last_response')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });

        Schema::create('ozon_delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ozon_delivery_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ozon_shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tracking_number', 80);
            $table->timestamps();
            $table->unique(['ozon_delivery_note_id', 'tracking_number'], 'ozon_dn_items_unique');
        });

        // Failed Ozon API calls (no API key ever stored: URLs/messages are redacted).
        Schema::create('ozon_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40); // create_parcel | parcel_info | tracking | tracking_bulk | delivery_note | test | cities
            $table->string('endpoint', 80);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ozon_shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ozon_delivery_note_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('message', 1000);
            $table->json('payload')->nullable();  // redacted request fields
            $table->json('response')->nullable(); // redacted response
            $table->json('context')->nullable();  // what "Réessayer" needs (order ids, options…)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('retried_at')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_api_logs');
        Schema::dropIfExists('ozon_delivery_note_items');
        Schema::dropIfExists('ozon_shipments');
        Schema::dropIfExists('ozon_delivery_notes');
        Schema::dropIfExists('ozon_city_mappings');
        Schema::dropIfExists('ozon_cities');
        Schema::dropIfExists('ozon_settings');
    }
};
