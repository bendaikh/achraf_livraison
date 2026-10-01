<?php

namespace Database\Seeders;

use App\Models\DeliveryStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // User #1 = the admin used as "current user" until authentication exists.
        User::query()->firstOrCreate(
            ['email' => 'brahim@lavafast.ma'],
            ['name' => 'Brahim', 'password' => bcrypt('password')],
        );

        $this->call(DeliveryStatusSeeder::class);

        Setting::setValue('company_name', 'Lavafast Livraison');
        Setting::setValue('confirmation_alert_hours', 24);
        // Default driver tariffs (DH) used to prefill new drivers.
        Setting::setValue('default_tariffs', [
            'livraison' => 20, 'ramassage' => 10, 'depot_partenaire' => 6, 'retour' => 7, 'echange' => 7,
        ]);
        Setting::setValue('enforce_status_transitions', false);
        Setting::setValue('status_on_confirm_id', DeliveryStatus::where('code', 'a_attribuer')->value('id'));
        Setting::setValue('status_on_assign_id', DeliveryStatus::where('code', 'attribuee')->value('id'));
    }
}
