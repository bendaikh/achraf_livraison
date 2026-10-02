<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paramètres → Partenaires logistiques (per company).
        Schema::create('logistics_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('both'); // ramassage | depot | both
            $table->string('phone', 40)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'type']);
        });

        // Missions keep the partner link + a frozen copy of the partner record at creation time.
        Schema::table('missions', function (Blueprint $table) {
            $table->foreignId('logistics_partner_id')->nullable()->constrained('logistics_partners')->nullOnDelete();
            $table->json('partner_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logistics_partner_id');
            $table->dropColumn('partner_snapshot');
        });
        Schema::dropIfExists('logistics_partners');
    }
};
