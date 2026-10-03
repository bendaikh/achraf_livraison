<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** T5 — Centre de confirmation: call log, discounts, confirmation channel. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result', 30); // answered | no_answer | callback | wrong_number
            $table->string('channel', 20)->default('phone'); // phone | whatsapp | voip
            $table->unsignedInteger('duration_seconds')->nullable(); // filled later by telephony (VoIP)
            $table->text('note')->nullable();
            $table->timestamp('called_at')->index();
            $table->timestamps();
            $table->index(['order_id', 'called_at']);
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10); // amount | percent
            $table->decimal('value', 10, 2);
            $table->decimal('amount', 10, 2); // DH removed from the total
            $table->string('reason', 255)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'confirmation_channel')) {
                $table->string('confirmation_channel', 20)->nullable()->after('confirmation_status');
            }
            if (! Schema::hasColumn('orders', 'discount_total')) {
                $table->decimal('discount_total', 10, 2)->default(0)->after('total_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['confirmation_channel', 'discount_total']);
        });
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('order_calls');
    }
};
