<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_statuses', function (Blueprint $table) {
            // e.g. ["postponed_at"], ["reason"], ["collected_amount"] — enforced on status change.
            $table->json('required_fields')->nullable();
            // Optional mission generated for the order's driver when entering this status (retour / echange).
            $table->string('creates_mission_type', 30)->nullable();
        });

        // Allowed workflow transitions (enforcement is a company setting).
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_status_id')->constrained('delivery_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('delivery_statuses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['from_status_id', 'to_status_id']);
        });

        // Immutable history: keeps a snapshot of the status so renames/deactivation never break it.
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('kind', 20)->default('livraison'); // livraison | confirmation
            $table->foreignId('delivery_status_id')->nullable()->constrained('delivery_statuses')->nullOnDelete();
            $table->string('status_code', 60);
            $table->string('status_name', 100);
            $table->string('status_color', 20)->nullable();
            $table->string('status_category', 40)->nullable();
            $table->foreignId('from_status_id')->nullable()->constrained('delivery_statuses')->nullOnDelete();
            $table->string('from_status_name', 100)->nullable();
            $table->json('data')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('status_transitions');
        Schema::table('delivery_statuses', function (Blueprint $table) {
            $table->dropColumn(['required_fields', 'creates_mission_type']);
        });
    }
};
