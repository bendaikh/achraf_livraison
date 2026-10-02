<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intégrations → Speedaf: per-company API settings, one row per waybill (shipment) created
 * from a Commande, and the received webhook events (idempotency on eventId).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speedaf_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('environment', 20)->default('uat'); // uat | production
            $table->string('app_code', 64)->nullable();
            $table->string('customer_code', 64)->nullable();
            $table->text('secret_key')->nullable(); // encrypted cast (webhook HMAC key)
            $table->string('platform_source', 100)->nullable();

            // Shipment defaults (Speedaf appendix codes)
            $table->string('parcel_type', 10)->default('PT01');
            $table->string('delivery_type', 10)->default('DE01');
            $table->string('transport_type', 10)->default('TT01');
            $table->string('ship_type', 10)->default('ST01');
            $table->string('pay_method', 10)->default('PA02');
            $table->string('goods_type', 10)->default('IT01');
            $table->unsignedTinyInteger('pickup_aging')->default(0);
            $table->boolean('allow_open')->default(false);
            $table->decimal('default_weight', 10, 3)->default(1);
            $table->string('country_code', 4)->default('MA');
            $table->string('currency', 4)->default('MAD');
            $table->unsignedSmallInteger('label_type')->default(2);
            $table->boolean('label_with_logo')->default(true);

            // Sender / pickup defaults
            $table->string('sender_name', 100)->nullable();
            $table->string('sender_mobile', 30)->nullable();
            $table->string('sender_address', 500)->nullable();
            $table->string('sender_province', 100)->nullable();
            $table->string('sender_city', 100)->nullable();
            $table->string('sender_district', 100)->nullable();

            // Speedaf action code => delivery_statuses.code (null = ignore)
            $table->json('status_mapping')->nullable();
            $table->boolean('auto_sync')->default(true);
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->timestamp('webhook_subscribed_at')->nullable();

            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('speedaf_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20)->default('uat');
            $table->string('bill_code', 60)->nullable()->index();
            $table->string('custom_order_no', 60)->nullable();
            $table->string('state', 20)->default('created'); // created | cancelled | delivered | returned
            $table->string('last_action', 20)->nullable();
            $table->string('last_sub_action', 20)->nullable();
            $table->string('last_action_name', 120)->nullable();
            $table->string('last_message', 500)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->string('label_url', 500)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('create_response')->nullable();
            $table->json('tracks')->nullable();
            $table->json('last_response')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });

        Schema::create('speedaf_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id', 191)->unique();
            $table->string('mail_no', 60)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speedaf_webhook_events');
        Schema::dropIfExists('speedaf_shipments');
        Schema::dropIfExists('speedaf_settings');
    }
};
