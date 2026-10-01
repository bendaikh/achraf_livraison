<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company-level settings (key / JSON value).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Per-user UI preferences (e.g. Commandes column selector).
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        // Delivery statuses — fully configurable (no hard-coded list).
        Schema::create('delivery_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 60)->unique();
            $table->string('color', 20)->default('#64748b');
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('category', 40)->index();
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('vehicle', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->string('product_name')->nullable();
            $table->string('product_image')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('customer_name');
            $table->string('customer_phone', 40)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('payment_method', 30)->default('cod');
            $table->string('confirmation_status', 30)->default('a_confirmer')->index();
            $table->foreignId('delivery_status_id')->nullable()->constrained('delivery_statuses')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->string('carrier', 60)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 60)->nullable();
            $table->text('note')->nullable();
            $table->string('status_reason')->nullable();
            $table->dateTime('postponed_at')->nullable();
            $table->decimal('collected_amount', 10, 2)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('status_changed_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
            $table->index('status_changed_at');
        });

        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->string('type', 30)->index();
            $table->string('status', 30)->default('a_faire')->index();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->text('items_description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('scheduled_date')->nullable()->index();
            $table->string('time_slot', 60)->nullable();
            $table->decimal('cash_amount', 10, 2)->nullable();
            $table->string('cash_direction', 20)->nullable(); // collect | remit
            $table->text('note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('delivery_statuses');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('settings');
    }
};
