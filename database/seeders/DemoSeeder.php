<?php

namespace Database\Seeders;

use App\Models\DeliveryStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Services\ClosingService;
use App\Services\MissionService;
use App\Services\OrderWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo data — run manually: php artisan db:seed --class=DemoSeeder
 * Everything goes through the real workflow services so missions, price snapshots
 * and status history are consistent with what the app would produce.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $workflow = app(OrderWorkflow::class);
        $missions = app(MissionService::class);
        $now = Carbon::now()->toImmutable();

        $yassine = Driver::create([
            'name' => 'Yassine Alaoui', 'phone' => '06 61 23 45 67', 'city' => 'Casablanca', 'vehicle' => 'Moto',
            'tariff_livraison' => 20, 'tariff_ramassage' => 10, 'tariff_depot_partenaire' => 6, 'tariff_retour' => 7, 'tariff_echange' => 7,
        ]);
        $achraf = Driver::create([
            'name' => 'Achraf Benali', 'phone' => '06 70 11 22 33', 'city' => 'Casablanca', 'vehicle' => 'Moto',
            'tariff_livraison' => 22, 'tariff_ramassage' => 12, 'tariff_depot_partenaire' => 7, 'tariff_retour' => 8, 'tariff_echange' => 9,
        ]);
        $karim = Driver::create([
            'name' => 'Karim Tazi', 'phone' => '07 12 98 76 54', 'city' => 'Rabat', 'vehicle' => 'Voiture',
            'tariff_livraison' => 18, 'tariff_ramassage' => 9, 'tariff_depot_partenaire' => 5, 'tariff_retour' => 6, 'tariff_echange' => 6,
        ]);

        $s = DeliveryStatus::pluck('id', 'code');
        $status = fn (string $code) => DeliveryStatus::find($s[$code]);

        // [client, phone, city, product, amount, source, days ago, hour, scenario, driver]
        $rows = [
            ['Fatima Zahra', '06 12 34 56 78', 'Casablanca', 'Sac à main cuir', 250, 'Site web', 0, 9, 'livree', $yassine],
            ['Omar Bennani', '06 98 76 54 32', 'Casablanca', 'Montre connectée', 399, 'WhatsApp', 0, 10, 'en_cours', $yassine],
            ['Sara El Amrani', '07 11 22 33 44', 'Mohammédia', 'Parfum 100 ml', 320, 'Instagram', 0, 10, 'a_confirmer', null],
            ['Youssef Kadiri', '06 55 66 77 88', 'Casablanca', 'Écouteurs sans fil', 180, 'Facebook', 0, 11, 'pas_de_reponse_conf', null],
            ['Nadia Cherkaoui', '06 44 33 22 11', 'Rabat', 'Robe été', 210, 'Site web', 0, 11, 'confirmee', null],
            ['Mehdi Lahlou', '07 99 88 77 66', 'Rabat', 'Chaussures sport', 450, 'Site web', 0, 12, 'attribuee', $karim],
            ['Imane Saadi', '06 22 44 66 88', 'Casablanca', 'Coffret soin', 199, 'WhatsApp', 0, 12, 'reportee', $achraf],
            ['Hicham Ouazzani', '06 33 55 77 99', 'Casablanca', 'Lunettes de soleil', 150, 'Instagram', 0, 13, 'a_confirmer', null],
            ['Salma Idrissi', '06 10 20 30 40', 'Casablanca', 'Sac à dos', 175, 'Site web', 0, 14, 'livree', $achraf],
            ['Rachid Amrani', '06 50 60 70 80', 'Salé', 'Montre classique', 520, 'Facebook', 1, 9, 'livree', $yassine],
            ['Khadija Berrada', '07 01 02 03 04', 'Casablanca', 'Ensemble pyjama', 140, 'WhatsApp', 1, 11, 'echouee', $achraf],
            ['Anas Tahiri', '06 15 25 35 45', 'Casablanca', 'Casque audio', 299, 'Site web', 1, 15, 'annulee_conf', null],
            ['Zineb Fassi', '06 27 37 47 57', 'Rabat', 'Parfum 50 ml', 230, 'Instagram', 1, 16, 'livree', $karim],
            ['Hamza Chraibi', '06 89 79 69 59', 'Casablanca', 'Basket enfant', 160, 'Site web', 2, 10, 'retour', $yassine],
            ['Laila Bennis', '06 48 58 68 78', 'Mohammédia', 'Bracelet argent', 260, 'WhatsApp', 2, 12, 'livree', $yassine],
            ['Soufiane Naciri', '06 31 41 51 61', 'Casablanca', 'Chargeur rapide', 99, 'Facebook', 3, 9, 'pas_de_reponse', $achraf],
            ['Meryem Alami', '06 72 82 92 02', 'Rabat', 'Sac de voyage', 380, 'Site web', 4, 14, 'livree', $karim],
            ['Adil Sqalli', '06 13 23 33 43', 'Casablanca', 'T-shirt pack x3', 189, 'Instagram', 5, 10, 'echange', $yassine],
            ['Hajar Kettani', '06 64 74 84 94', 'Casablanca', 'Crème visage', 120, 'WhatsApp', 6, 11, 'a_confirmer', null],
            ['Ilyas Bouzid', '06 36 46 56 66', 'Salé', 'Montre sport', 340, 'Site web', 8, 9, 'livree', $yassine],
            ['Kenza Mansouri', '06 17 27 37 47', 'Casablanca', 'Foulard soie', 110, 'Facebook', 12, 15, 'annulee', $achraf],
        ];

        foreach ($rows as [$client, $phone, $city, $product, $amount, $source, $daysAgo, $hour, $scenario, $driver]) {
            Carbon::setTestNow();
            $at = $now->copy()->subDays($daysAgo)->setTime($hour, rand(0, 59));
            // Leave room for the simulated workflow steps (~2h40) so nothing lands in the future.
            $latest = $now->copy()->subMinutes(170);
            if ($at->gt($latest)) {
                $at = $latest->copy()->subMinutes(rand(0, 20));
            }
            Carbon::setTestNow($at);

            $order = Order::create([
                'customer_name' => $client, 'customer_phone' => $phone, 'city' => $city,
                'address' => rand(1, 120).', Rue '.['Ibn Batouta', 'Al Massira', 'Hassan II', 'Zerktouni', 'Anfa'][rand(0, 4)],
                'product_name' => $product, 'quantity' => 1, 'amount' => $amount, 'payment_method' => 'cod',
                'source' => $source, 'assigned_user_id' => 1,
            ]);

            $step = fn (int $minutes) => Carbon::setTestNow(Carbon::now()->addMinutes($minutes));

            match ($scenario) {
                'a_confirmer' => null,
                'pas_de_reponse_conf' => $workflow->changeConfirmation($order, 'pas_de_reponse'),
                'annulee_conf' => $workflow->changeConfirmation($order, 'annulee'),
                default => $workflow->changeConfirmation($order, 'confirmee'),
            };

            if ($driver) {
                $step(20);
                $workflow->assignDriver($order, $driver->id);
            }

            $step(45);
            match ($scenario) {
                'en_cours' => $workflow->changeStatus($order, $status('en_cours')),
                'livree' => [$workflow->changeStatus($order, $status('en_cours')), $step(60), $workflow->changeStatus($order, $status('livree'), ['collected_amount' => $amount])],
                'reportee' => [$workflow->changeStatus($order, $status('en_cours')), $step(30), $workflow->changeStatus($order, $status('reportee'), ['postponed_at' => $now->copy()->subHours(1)->format('Y-m-d H:i'), 'reason' => 'Client en déplacement'])],
                'echouee' => [$workflow->changeStatus($order, $status('en_cours')), $step(30), $workflow->changeStatus($order, $status('echouee'), ['reason' => 'Adresse introuvable'])],
                'pas_de_reponse' => [$workflow->changeStatus($order, $status('en_cours')), $step(30), $workflow->changeStatus($order, $status('pas_de_reponse'))],
                'retour' => [$workflow->changeStatus($order, $status('en_cours')), $step(30), $workflow->changeStatus($order, $status('retour'), ['reason' => 'Refusé par le client'])],
                'echange' => [$workflow->changeStatus($order, $status('en_cours')), $step(30), $workflow->changeStatus($order, $status('echange'))],
                'annulee' => $workflow->changeStatus($order, $status('annulee'), ['reason' => 'Client a annulé']),
                default => null,
            };
        }

        // Karim's cash was closed yesterday evening (30 DH short) — shows closed amount & remaining.
        Carbon::setTestNow($now->subDay()->setTime(20, 0));
        $pending = app(ClosingService::class)->pending($karim->id);
        app(ClosingService::class)->close($karim, max(0, $pending['cod'] - 30), 'Clôture de démonstration (30 DH manquants)');

        Carbon::setTestNow();

        // Standalone missions (ramassage / dépôt partenaire).
        $today = $now->toDateString();
        $missions->create(['type' => 'ramassage', 'driver_id' => $yassine->id, 'contact_name' => 'Boutique Zahra', 'phone' => '05 22 11 22 33', 'address' => '12, Bd Anfa', 'city' => 'Casablanca', 'items_description' => 'Colis à récupérer', 'quantity' => 6, 'scheduled_date' => $today, 'time_slot' => '10:00 - 12:00']);
        $missions->create(['type' => 'ramassage', 'driver_id' => $achraf->id, 'contact_name' => 'Atelier Couture Nour', 'phone' => '06 00 11 22 33', 'address' => '45, Rue Mozart', 'city' => 'Casablanca', 'items_description' => 'Robes', 'quantity' => 10, 'scheduled_date' => $now->copy()->subDay()->toDateString(), 'time_slot' => '14:00 - 16:00', 'cash_amount' => 300, 'cash_direction' => 'remit']);
        $missions->create(['type' => 'depot_partenaire', 'driver_id' => $yassine->id, 'contact_name' => 'Agence Ozone Maârif', 'address' => 'Bd Brahim Roudani', 'city' => 'Casablanca', 'items_description' => 'Colis villes éloignées', 'quantity' => 12, 'scheduled_date' => $today, 'time_slot' => '16:00 - 18:00']);
        $done = $missions->create(['type' => 'depot_partenaire', 'driver_id' => $karim->id, 'contact_name' => 'Speedaf Rabat Agdal', 'address' => 'Av. Fal Ould Oumeir', 'city' => 'Rabat', 'items_description' => 'Colis', 'quantity' => 4, 'scheduled_date' => $today]);
        $missions->changeStatus($done, 'terminee');
    }
}
