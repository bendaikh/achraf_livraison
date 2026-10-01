<?php

namespace Database\Seeders;

use App\Models\ConfirmationStatus;
use Illuminate\Database\Seeder;

class ConfirmationStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            [
                'code' => 'to_confirm',
                'name' => 'À confirmer',
                'color' => '#D97706',
                'icon' => 'clock',
                'sort_order' => 10,
                'type' => ConfirmationStatus::TYPE_OPEN,
                'filter_label' => 'À confirmer',
                'is_default' => true,
                'is_terminal' => false,
                'queue_behavior' => ConfirmationStatus::BEHAVIOR_DUE_QUEUE,
            ],
            [
                'code' => 'postponed',
                'name' => 'Reportée',
                'color' => '#7C3AED',
                'icon' => 'calendar-clock',
                'sort_order' => 20,
                'type' => ConfirmationStatus::TYPE_WAITING,
                'filter_label' => 'Reportées',
                'is_default' => false,
                'is_terminal' => false,
                'queue_behavior' => ConfirmationStatus::BEHAVIOR_FUTURE_ONLY,
            ],
            [
                'code' => 'no_answer',
                'name' => 'Pas de réponse',
                'color' => '#EA580C',
                'icon' => 'phone-off',
                'sort_order' => 30,
                'type' => ConfirmationStatus::TYPE_WAITING,
                'filter_label' => 'Pas de réponse',
                'is_default' => false,
                'is_terminal' => false,
                'queue_behavior' => null,
            ],
            [
                'code' => 'confirmed',
                'name' => 'Confirmée',
                'color' => '#059669',
                'icon' => 'check-circle',
                'sort_order' => 40,
                'type' => ConfirmationStatus::TYPE_SUCCESS,
                'filter_label' => 'Confirmées',
                'is_default' => false,
                'is_terminal' => true,
                'queue_behavior' => null,
            ],
            [
                'code' => 'cancelled',
                'name' => 'Annulée',
                'color' => '#E11D48',
                'icon' => 'x-circle',
                'sort_order' => 50,
                'type' => ConfirmationStatus::TYPE_CANCELLED,
                'filter_label' => 'Annulées',
                'is_default' => false,
                'is_terminal' => true,
                'queue_behavior' => null,
            ],
        ];

        foreach ($statuses as $data) {
            ConfirmationStatus::query()->updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'is_active' => true,
                    'show_in_filters' => true,
                ]),
            );
        }

        ConfirmationStatus::flushCache();
    }
}
