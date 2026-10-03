<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T7 — Retours / échanges (SAV) on delivered orders, driver traceability. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sav_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 32)->nullable()->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // retour | echange
            $table->string('status', 32)->index();
            $table->string('reason', 80);
            $table->text('comment')->nullable();
            $table->text('sav_note')->nullable();
            // Snapshot of the original order (auto-filled at creation)
            $table->string('customer_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->foreignId('original_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamp('original_delivered_at')->nullable();
            // Current handling
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('mission_id')->nullable()->constrained('missions')->nullOnDelete();
            $table->decimal('driver_fee', 10, 2)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('postponed_until')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('new_delivered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sav_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sav_request_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16); // pickup (from client) | deliver (new product to client)
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_key', 64)->nullable(); // original order line key
            $table->string('title');
            $table->string('variant_title')->nullable();
            $table->string('sku', 120)->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->nullable();
            // pending → with_driver → at_depot (pickup) | delivered (deliver) | returned (deliver, back to depot)
            $table->string('state', 16)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('sav_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sav_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('event', 32)->default('status');
            $table->string('label');
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sav_status_histories');
        Schema::dropIfExists('sav_request_items');
        Schema::dropIfExists('sav_requests');
    }
};
