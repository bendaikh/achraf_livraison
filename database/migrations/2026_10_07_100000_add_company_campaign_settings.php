<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company settings for WhatsApp campaigns: timezone, vehicles feature flag, consent & rate rules.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'timezone')) {
                $table->string('timezone', 64)->default('Africa/Casablanca')->after('is_active');
            }
            if (! Schema::hasColumn('companies', 'features')) {
                $table->json('features')->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('companies', 'campaign_settings')) {
                $table->json('campaign_settings')->nullable()->after('features');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'campaign_settings')) {
                $table->dropColumn('campaign_settings');
            }
            if (Schema::hasColumn('companies', 'features')) {
                $table->dropColumn('features');
            }
            if (Schema::hasColumn('companies', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
