<?php

namespace App\Services\Automations\Actions;

use App\Models\Order;
use App\Models\User;
use App\Services\Shopify\ShopifyOrderEditService;
use Illuminate\Validation\ValidationException;

class ShopifyAddOrderNoteAction extends BaseAction
{
    public function key(): string
    {
        return 'shopify.add_order_note';
    }

    public function label(): string
    {
        return 'Shopify · Ajouter une note';
    }

    public function integration(): string
    {
        return 'shopify';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'note', 'label' => 'Note', 'type' => 'string'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $note = (string) ($config['note'] ?? '');
        if ($simulate) {
            return ['ok' => true, 'simulated' => true, 'output' => ['note' => $note]];
        }
        $order = Order::query()->find($context['order']['id'] ?? null);
        if (! $order) {
            return ['ok' => false, 'error' => 'Commande introuvable.'];
        }
        $user = User::query()->find($context['trigger']['user_id'] ?? null) ?? $order->assignedUser;
        if (! $user) {
            return ['ok' => false, 'error' => 'Utilisateur requis pour écrire la note Shopify.'];
        }
        try {
            app(ShopifyOrderEditService::class)->updateCustomer($order, ['note' => trim(($order->note ? $order->note."\n" : '').$note)], $user);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];
        }

        return ['ok' => true, 'output' => ['note' => $note]];
    }
}
