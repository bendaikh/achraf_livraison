<?php

namespace Database\Seeders;

use App\Models\DeliveryStatus;
use Illuminate\Database\Seeder;

/** The 10 default delivery statuses. They remain fully editable in Paramètres → Statuts de livraison. */
class DeliveryStatusSeeder extends Seeder
{
    public const DEFAULTS = [
        ['code' => 'a_attribuer', 'name' => 'À attribuer', 'category' => 'avant_livraison', 'color' => '#64748b', 'icon' => 'inbox'],
        ['code' => 'attribuee', 'name' => 'Attribuée', 'category' => 'avant_livraison', 'color' => '#6366f1', 'icon' => 'user-check'],
        ['code' => 'en_cours', 'name' => 'En cours', 'category' => 'en_livraison', 'color' => '#2563eb', 'icon' => 'truck'],
        ['code' => 'livree', 'name' => 'Livrée', 'category' => 'succes', 'color' => '#16a34a', 'icon' => 'check-circle'],
        ['code' => 'pas_de_reponse', 'name' => 'Pas de réponse', 'category' => 'injoignable', 'color' => '#a855f7', 'icon' => 'phone-off'],
        ['code' => 'reportee', 'name' => 'Reportée', 'category' => 'report', 'color' => '#f59e0b', 'icon' => 'calendar-clock'],
        ['code' => 'echouee', 'name' => 'Échouée', 'category' => 'echec', 'color' => '#dc2626', 'icon' => 'x-circle'],
        ['code' => 'annulee', 'name' => 'Annulée', 'category' => 'annulation', 'color' => '#e11d48', 'icon' => 'ban'],
        ['code' => 'retour', 'name' => 'Retour', 'category' => 'retour', 'color' => '#ea580c', 'icon' => 'undo'],
        ['code' => 'echange', 'name' => 'Échange', 'category' => 'retour', 'color' => '#0d9488', 'icon' => 'repeat'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            DeliveryStatus::query()->updateOrCreate(
                ['code' => $row['code']],
                $row + ['sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }
    }
}
