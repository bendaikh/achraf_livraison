<?php

namespace App\Services\Automations\Actions;

use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Services\Confirmation\ConfirmationStatusChanger;
use App\Support\CurrentUser;
use Illuminate\Validation\ValidationException;

class SetConfirmationStatusAction extends BaseAction
{
    public function key(): string
    {
        return 'order.set_confirmation_status';
    }

    public function label(): string
    {
        return 'Changer le statut de confirmation';
    }

    public function integration(): string
    {
        return 'internal';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'status_code', 'label' => 'Statut de confirmation', 'type' => 'select', 'required' => true, 'options' => []],
            ['key' => 'reason', 'label' => 'Motif', 'type' => 'string'],
            ['key' => 'comment', 'label' => 'Commentaire', 'type' => 'string'],
            ['key' => 'recall_at', 'label' => 'Date de rappel', 'type' => 'datetime'],
            ['key' => 'recall_time', 'label' => 'Heure', 'type' => 'time'],
            ['key' => 'product_line_key', 'label' => 'Produit concerné', 'type' => 'string'],
            ['key' => 'expected_restock_date', 'label' => 'Réapprovisionnement prévu', 'type' => 'date'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $code = trim((string) ($config['status_code'] ?? ''));
        $orderId = (int) ($context['order']['id'] ?? $context['subject_id'] ?? 0);
        $order = Order::query()->find($orderId);
        if (! $order) {
            return ['ok' => false, 'error' => "Commande #{$orderId} introuvable."];
        }
        if ($code === '') {
            return ['ok' => false, 'error' => 'Le statut de confirmation est obligatoire.'];
        }

        $companyId = (int) ($order->company_id ?: ConfirmationStatus::resolveCompanyId());
        $status = ConfirmationStatus::findByCode($code, $companyId);
        if (! $status || (int) $status->company_id !== $companyId || ! $status->is_active) {
            return ['ok' => false, 'error' => 'Ce statut est inactif ou n’appartient pas à cette société.'];
        }

        $changer = app(ConfirmationStatusChanger::class);
        try {
            $changer->assertRequired($order, $status, $config);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Statut de confirmation refusé.';

            return ['ok' => false, 'simulated' => $simulate, 'error' => $message];
        }

        if ($simulate) {
            return [
                'ok' => true,
                'simulated' => true,
                'output' => [
                    'status_code' => $code,
                    'status_name' => $status->name,
                    'order_id' => $orderId,
                    'written' => false,
                ],
            ];
        }

        try {
            $changer->change($order, $status, CurrentUser::get(), $config);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Statut de confirmation refusé.';

            return ['ok' => false, 'error' => $message];
        }

        return [
            'ok' => true,
            'output' => [
                'status_code' => $order->fresh()->confirmation_status,
                'status_name' => $status->name,
                'order_id' => $orderId,
            ],
        ];
    }
}
