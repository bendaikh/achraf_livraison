<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T8 — Sift.ma carrier (Intégrations → Transporteurs → Sift.ma). Additive only: new tables,
 * nothing existing is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sift_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('base_url', 190)->default('https://apis.sift.ma/v1');
            $table->text('api_key')->nullable(); // encrypted cast, never sent to the browser
            $table->string('auth_mode', 12)->default('bearer'); // bearer | x-api-key
            $table->boolean('default_allow_open')->default(true);
            $table->string('items_mode', 12)->default('manual'); // manual | sku (lines linked to Sift stock)
            $table->boolean('send_note')->default(true);
            $table->string('waybill_format', 30)->default('STANDARD_100x100');
            $table->json('status_mapping')->nullable(); // raw Sift status => delivery_statuses.code|null
            $table->json('seen_statuses')->nullable();
            $table->boolean('auto_sync')->default(true); // polling fallback next to webhooks
            $table->string('webhook_token', 64)->nullable()->unique(); // in the webhook URL
            $table->text('webhook_secret')->nullable(); // encrypted, signature verification
            $table->string('webhook_remote_id', 120)->nullable(); // id returned by POST /webhooks
            $table->timestamp('webhook_last_received_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('sift_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('parcel_id', 120)->nullable()->index();
            $table->string('tracking_number', 120)->nullable()->index();
            $table->string('custom_order_no', 120)->nullable()->index();
            $table->string('state', 20)->default('created'); // created | delivered | returned | cancelled
            $table->string('receiver')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->boolean('allow_open')->nullable();
            $table->string('raw_status', 120)->nullable(); // Sift status kept as is
            $table->string('raw_sub_status', 120)->nullable();
            $table->string('raw_status_comment', 500)->nullable();
            $table->timestamp('status_at')->nullable();
            $table->string('mapped_status', 60)->nullable();
            $table->json('history')->nullable();
            $table->boolean('reused_existing')->default(false); // Sift returned an existing parcel for customOrderNo
            $table->json('request_payload')->nullable(); // body sent (never contains the key)
            $table->json('create_response')->nullable();
            $table->json('last_response')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable(); // « Supprimer / masquer » (DELETE = soft delete at Sift)
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });

        // Webhook deliveries (idempotency on event_id + troubleshooting; never stores the secret).
        Schema::create('sift_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_id', 191)->unique();
            $table->string('event_type', 60)->nullable();
            $table->string('status', 20)->default('received'); // received | processed | ignored | rejected | failed
            $table->string('tracking_number', 120)->nullable();
            $table->string('parcel_id', 120)->nullable();
            $table->foreignId('sift_shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable(); // header names received (values of signature headers dropped)
            $table->string('message', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('sift_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('endpoint', 120);
            $table->string('method', 8)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sift_shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('message', 1000);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('retried_at')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sift_api_logs');
        Schema::dropIfExists('sift_webhook_events');
        Schema::dropIfExists('sift_shipments');
        Schema::dropIfExists('sift_settings');
    }
};
