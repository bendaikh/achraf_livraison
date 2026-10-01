<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-driver mission tariffs (DH). Retour and Échange are separate on purpose.
        Schema::table('drivers', function (Blueprint $table) {
            $table->decimal('tariff_livraison', 8, 2)->default(0);
            $table->decimal('tariff_ramassage', 8, 2)->default(0);
            $table->decimal('tariff_depot_partenaire', 8, 2)->default(0);
            $table->decimal('tariff_retour', 8, 2)->default(0);
            $table->decimal('tariff_echange', 8, 2)->default(0);
        });

        // Price snapshot taken when the mission is assigned: never recomputed afterwards.
        Schema::table('missions', function (Blueprint $table) {
            $table->decimal('driver_price', 8, 2)->nullable();
            $table->dateTime('assigned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn(['driver_price', 'assigned_at']);
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['tariff_livraison', 'tariff_ramassage', 'tariff_depot_partenaire', 'tariff_retour', 'tariff_echange']);
        });
    }
};
