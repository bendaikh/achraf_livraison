<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Driver cash closing (Clôture du jour). Amounts are snapshots: COD money and
        // driver commissions are stored separately and never recomputed.
        Schema::create('closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();
            $table->date('closing_date')->index();
            $table->decimal('cod_expected', 12, 2)->default(0);
            $table->decimal('cod_remitted', 12, 2)->default(0);
            $table->decimal('commissions_total', 12, 2)->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('missions_count')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('closing_id')->nullable()->constrained('closings')->nullOnDelete();
        });
        Schema::table('missions', function (Blueprint $table) {
            $table->foreignId('closing_id')->nullable()->constrained('closings')->nullOnDelete();
        });

        Schema::create('mission_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')->constrained('missions')->cascadeOnDelete();
            $table->string('event', 30); // created | assigned | status
            $table->string('status', 30)->nullable();
            $table->string('label', 150);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_status_histories');
        Schema::table('missions', fn (Blueprint $t) => $t->dropConstrainedForeignId('closing_id'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('closing_id'));
        Schema::dropIfExists('closings');
    }
};
