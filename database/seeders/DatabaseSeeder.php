<?php

namespace Database\Seeders;

use App\Models\DeliveryStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ConfirmationStatusSeeder::class);
        $this->call(WhatsAppQuickReplySeeder::class);
        $this->call(DeliveryStatusSeeder::class);

        User::query()->updateOrCreate(
            ['email' => 'superadmin@lavfast-flow.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('SuperAdmin@2026'),
                'role' => 'superadmin',
                'email_verified_at' => now(),
            ]
        );

        // Company settings (Paramètres) — only set when missing so a re-seed never overwrites them.
        $defaults = [
            'company_name' => "Lav'Fast Flow",
            'confirmation_alert_hours' => 24,
            // Default driver tariffs (DH) used to prefill new drivers.
            'default_tariffs' => ['livraison' => 20, 'ramassage' => 10, 'depot_partenaire' => 6, 'retour' => 7, 'echange' => 7],
            'enforce_status_transitions' => false,
            'status_on_confirm_id' => DeliveryStatus::where('code', 'to_assign')->value('id'),
            'status_on_assign_id' => DeliveryStatus::where('code', 'assigned')->value('id'),
        ];
        foreach ($defaults as $key => $value) {
            if (! Setting::query()->where('key', $key)->exists()) {
                Setting::setValue($key, $value);
            }
        }
    }
}
