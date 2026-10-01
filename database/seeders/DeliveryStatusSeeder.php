<?php

namespace Database\Seeders;

use App\Models\DeliveryStatus;
use App\Models\StatusTransition;
use Illuminate\Database\Seeder;

/** The 10 default delivery statuses. They remain fully editable in Paramètres → Statuts de livraison. */
class DeliveryStatusSeeder extends Seeder
{
    public const DEFAULTS = [
        ['code' => 'a_attribuer', 'name' => 'À attribuer', 'category' => 'avant_livraison', 'color' => '#64748b', 'icon' => 'inbox'],
        ['code' => 'attribuee', 'name' => 'Attribuée', 'category' => 'avant_livraison', 'color' => '#6366f1', 'icon' => 'user-check'],
        ['code' => 'en_cours', 'name' => 'En cours', 'category' => 'en_livraison', 'color' => '#2563eb', 'icon' => 'truck'],
        ['code' => 'livree', 'name' => 'Livrée', 'category' => 'succes', 'color' => '#16a34a', 'icon' => 'check-circle', 'required_fields' => ['collected_amount']],
        ['code' => 'pas_de_reponse', 'name' => 'Pas de réponse', 'category' => 'injoignable', 'color' => '#a855f7', 'icon' => 'phone-off'],
        ['code' => 'reportee', 'name' => 'Reportée', 'category' => 'report', 'color' => '#f59e0b', 'icon' => 'calendar-clock', 'required_fields' => ['postponed_at']],
        ['code' => 'echouee', 'name' => 'Échouée', 'category' => 'echec', 'color' => '#dc2626', 'icon' => 'x-circle', 'required_fields' => ['reason']],
        ['code' => 'annulee', 'name' => 'Annulée', 'category' => 'annulation', 'color' => '#e11d48', 'icon' => 'ban', 'required_fields' => ['reason']],
        ['code' => 'retour', 'name' => 'Retour', 'category' => 'retour', 'color' => '#ea580c', 'icon' => 'undo', 'creates_mission_type' => 'retour'],
        ['code' => 'echange', 'name' => 'Échange', 'category' => 'retour', 'color' => '#0d9488', 'icon' => 'repeat', 'creates_mission_type' => 'echange'],
    ];

    /** Default workflow (from => [to…]). Enforcement is off by default (Paramètres → Statuts). */
    public const TRANSITIONS = [
        'a_attribuer' => ['attribuee', 'annulee'],
        'attribuee' => ['en_cours', 'a_attribuer', 'annulee'],
        'en_cours' => ['livree', 'pas_de_reponse', 'reportee', 'echouee', 'retour', 'echange', 'annulee'],
        'pas_de_reponse' => ['reportee', 'en_cours', 'echouee', 'annulee', 'retour'],
        'reportee' => ['en_cours', 'annulee'],
        'echouee' => ['retour', 'reportee'],
        'livree' => ['echange', 'retour'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            DeliveryStatus::query()->updateOrCreate(
                ['code' => $row['code']],
                $row + ['sort_order' => ($i + 1) * 10, 'is_active' => true, 'required_fields' => [], 'creates_mission_type' => null],
            );
        }

        $ids = DeliveryStatus::pluck('id', 'code');
        foreach (self::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                StatusTransition::firstOrCreate(['from_status_id' => $ids[$from], 'to_status_id' => $ids[$to]]);
            }
        }
    }
}
