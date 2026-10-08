<?php

namespace App\Services\Confirmation;

use App\Models\ConfirmationStatus;
use App\Models\Order;

/** Seeds the five system confirmation statuses for one company. Idempotent. */
class ConfirmationStatusProvisioner
{
    public function provision(int $companyId): void
    {
        foreach (self::definitions() as $data) {
            ConfirmationStatus::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $data['code']],
                $data + ['company_id' => $companyId, 'is_active' => true, 'show_in_filters' => true],
            );
        }

        ConfirmationStatus::flushCache($companyId);
    }

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'code' => Order::CONFIRMATION_TO_CONFIRM,
                'name' => 'À confirmer',
                'color' => '#D97706',
                'icon' => 'clock',
                'sort_order' => 10,
                'type' => ConfirmationStatus::TYPE_OPEN,
                'filter_label' => 'À confirmer',
                'is_default' => true,
                'is_terminal' => false,
                'is_final' => false,
                'is_system' => true,
                'queue_behavior' => ConfirmationStatus::BEHAVIOR_DUE_QUEUE,
                'category' => ConfirmationStatus::CATEGORY_WAITING,
                'stays_in_queue' => true,
                'counts_as_confirmed' => false,
                'counts_as_failure' => false,
                'requires_recall_date' => false,
                'requires_time' => false,
                'requires_reason' => false,
                'requires_comment' => false,
                'requires_product' => false,
            ],
            [
                'code' => Order::CONFIRMATION_POSTPONED,
                'name' => 'Reportée',
                'color' => '#7C3AED',
                'icon' => 'calendar-clock',
                'sort_order' => 20,
                'type' => ConfirmationStatus::TYPE_WAITING,
                'filter_label' => 'Reportées',
                'is_default' => false,
                'is_terminal' => false,
                'is_final' => false,
                'is_system' => true,
                'queue_behavior' => ConfirmationStatus::BEHAVIOR_FUTURE_ONLY,
                'category' => ConfirmationStatus::CATEGORY_RECALL,
                'stays_in_queue' => false,
                'counts_as_confirmed' => false,
                'counts_as_failure' => false,
                'requires_recall_date' => true,
                'requires_time' => true,
                'requires_reason' => false,
                'requires_comment' => false,
                'requires_product' => false,
            ],
            [
                'code' => Order::CONFIRMATION_NO_ANSWER,
                'name' => 'Pas de réponse',
                'color' => '#EA580C',
                'icon' => 'phone-off',
                'sort_order' => 30,
                'type' => ConfirmationStatus::TYPE_WAITING,
                'filter_label' => 'Pas de réponse',
                'is_default' => false,
                'is_terminal' => false,
                'is_final' => false,
                'is_system' => true,
                'queue_behavior' => null,
                'category' => ConfirmationStatus::CATEGORY_NO_ANSWER,
                'stays_in_queue' => true,
                'counts_as_confirmed' => false,
                'counts_as_failure' => false,
                'requires_recall_date' => false,
                'requires_time' => false,
                'requires_reason' => false,
                'requires_comment' => false,
                'requires_product' => false,
            ],
            [
                'code' => Order::CONFIRMATION_CONFIRMED,
                'name' => 'Confirmée',
                'color' => '#059669',
                'icon' => 'check-circle',
                'sort_order' => 40,
                'type' => ConfirmationStatus::TYPE_SUCCESS,
                'filter_label' => 'Confirmées',
                'is_default' => false,
                'is_terminal' => true,
                'is_final' => true,
                'is_system' => true,
                'queue_behavior' => null,
                'category' => ConfirmationStatus::CATEGORY_CONFIRMED,
                'stays_in_queue' => false,
                'counts_as_confirmed' => true,
                'counts_as_failure' => false,
                'requires_recall_date' => false,
                'requires_time' => false,
                'requires_reason' => false,
                'requires_comment' => false,
                'requires_product' => false,
            ],
            [
                'code' => Order::CONFIRMATION_CANCELLED,
                'name' => 'Annulée',
                'color' => '#E11D48',
                'icon' => 'x-circle',
                'sort_order' => 50,
                'type' => ConfirmationStatus::TYPE_CANCELLED,
                'filter_label' => 'Annulées',
                'is_default' => false,
                'is_terminal' => true,
                'is_final' => true,
                'is_system' => true,
                'queue_behavior' => null,
                'category' => ConfirmationStatus::CATEGORY_FAILED,
                'stays_in_queue' => false,
                'counts_as_confirmed' => false,
                'counts_as_failure' => true,
                'requires_recall_date' => false,
                'requires_time' => false,
                'requires_reason' => true,
                'requires_comment' => false,
                'requires_product' => false,
            ],
        ];
    }
};
