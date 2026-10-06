<?php

namespace App\Services\Automations\Actions;

use App\Models\Order;

class AddNoteAction extends BaseAction
{
    public function key(): string
    {
        return 'internal.add_note';
    }

    public function label(): string
    {
        return 'Ajouter une note interne';
    }

    public function integration(): string
    {
        return 'internal';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'required' => true],
            ['key' => 'field', 'label' => 'Champ', 'type' => 'select', 'options' => [
                ['value' => 'internal_note', 'label' => 'Note interne'],
                ['value' => 'note', 'label' => 'Note client'],
            ], 'default' => 'internal_note'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $note = trim((string) ($config['note'] ?? ''));
        if ($note === '') {
            return ['ok' => false, 'error' => 'Note vide.'];
        }
        $field = ($config['field'] ?? 'internal_note') === 'note' ? 'note' : 'internal_note';
        $orderId = (int) ($context['order']['id'] ?? $context['subject_id'] ?? 0);

        if ($simulate || empty($context['order']['id'])) {
            return [
                'ok' => true,
                'simulated' => true,
                'output' => ['field' => $field, 'note' => $note, 'order_id' => $orderId],
            ];
        }

        $order = Order::query()->find($orderId);
        if (! $order) {
            return ['ok' => false, 'error' => "Commande #{$orderId} introuvable."];
        }

        $previous = (string) ($order->{$field} ?? '');
        $separator = $previous !== '' ? "\n---\n" : '';
        $order->forceFill([$field => $previous.$separator.'[Auto] '.$note])->save();

        return [
            'ok' => true,
            'output' => ['field' => $field, 'note' => $note, 'order_id' => $order->id],
        ];
    }
}
