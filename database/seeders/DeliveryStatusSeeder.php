<?php

namespace Database\Seeders;

use App\Models\DeliveryStatus;
use App\Models\StatusTransition;
use Illuminate\Database\Seeder;

/** The 10 default delivery statuses. They remain fully editable in Paramètres → Statuts de livraison. */
class DeliveryStatusSeeder extends Seeder
{
    /** Same defaults as the foundation migration (codes shared with the existing delivery workflow). */
    public const DEFAULTS = [
        ['code' => 'to_assign', 'name' => 'À attribuer', 'category' => 'avant_livraison', 'color' => '#64748b', 'icon' => 'inbox'],
        ['code' => 'assigned', 'name' => 'Attribuée', 'category' => 'avant_livraison', 'color' => '#2563eb', 'icon' => 'user-check'],
        ['code' => 'in_progress', 'name' => 'En cours', 'category' => 'en_livraison', 'color' => '#0891b2', 'icon' => 'truck'],
        ['code' => 'delivered', 'name' => 'Livrée', 'category' => 'succes', 'color' => '#059669', 'icon' => 'check-circle', 'required_fields' => ['collected_amount']],
        ['code' => 'no_answer', 'name' => 'Pas de réponse', 'category' => 'injoignable', 'color' => '#64748b', 'icon' => 'phone-off', 'required_fields' => ['reason']],
        ['code' => 'postponed', 'name' => 'Reportée', 'category' => 'report', 'color' => '#d97706', 'icon' => 'calendar-clock', 'required_fields' => ['postponed_at']],
        ['code' => 'failed', 'name' => 'Échouée', 'category' => 'echec', 'color' => '#e11d48', 'icon' => 'x-circle', 'required_fields' => ['reason']],
        ['code' => 'cancelled', 'name' => 'Annulée', 'category' => 'annulation', 'color' => '#be123c', 'icon' => 'ban', 'required_fields' => ['reason']],
        ['code' => 'returned', 'name' => 'Retour', 'category' => 'retour', 'color' => '#ea580c', 'icon' => 'undo', 'creates_mission_type' => 'retour'],
        ['code' => 'exchanged', 'name' => 'Échange', 'category' => 'retour', 'color' => '#0d9488', 'icon' => 'repeat', 'creates_mission_type' => 'echange'],
    ];

    /** Default workflow (from => [to…]). Enforcement is off by default (Paramètres → Statuts). */
    public const TRANSITIONS = [
        'to_assign' => ['assigned', 'cancelled'],
        'assigned' => ['in_progress', 'to_assign', 'cancelled'],
        'in_progress' => ['delivered', 'no_answer', 'postponed', 'failed', 'returned', 'exchanged', 'cancelled'],
        'no_answer' => ['assigned', 'postponed', 'in_progress', 'failed', 'cancelled', 'returned'],
        'postponed' => ['in_progress', 'delivered', 'no_answer', 'failed', 'cancelled'],
        'failed' => ['assigned', 'returned', 'postponed'],
        'delivered' => ['exchanged', 'returned'],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $i => $row) {
            // Missing statuses only: statuses edited in Paramètres are never overwritten.
            DeliveryStatus::query()->firstOrCreate(
                ['code' => $row['code']],
                $row + ['sort_order' => ($i + 1) * 10, 'is_active' => true, 'required_fields' => [], 'creates_mission_type' => null],
            );
        }

        $ids = DeliveryStatus::pluck('id', 'code');
        if (StatusTransition::query()->exists()) {
            return;
        }
        foreach (self::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                if (! isset($ids[$from], $ids[$to])) {
                    continue;
                }
                StatusTransition::firstOrCreate(['from_status_id' => $ids[$from], 'to_status_id' => $ids[$to]]);
            }
        }
    }
}
