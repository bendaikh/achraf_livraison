<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T1 branding: the default company / setting names created by earlier seeds used old
 * variants ("Lavfast Flow", "Lavafast Livraison"). Only those exact default values are
 * renamed to "Lav'Fast Flow"; a name typed by a user is never touched. Data-only, no schema change.
 */
return new class extends Migration
{
    private array $old = ['Lavfast Flow', 'Lavafast Livraison', 'Lavfast Livraison', 'Lavfast', 'Lavafast', "LAV'FAST Flow"];

    public function up(): void
    {
        $brand = "Lav'Fast Flow";
        DB::table('companies')->whereIn('name', $this->old)->update(['name' => $brand]);
        foreach ($this->old as $old) {
            DB::table('settings')->where('key', 'company_name')->where('value', json_encode($old))->update(['value' => json_encode($brand)]);
        }
        if (DB::getSchemaBuilder()->hasTable('speedaf_settings')) {
            DB::table('speedaf_settings')->whereIn('platform_source', $this->old)->update(['platform_source' => $brand]);
        }
    }

    public function down(): void
    {
        // Irreversible on purpose (we can't know which old variant each row had).
    }
};
