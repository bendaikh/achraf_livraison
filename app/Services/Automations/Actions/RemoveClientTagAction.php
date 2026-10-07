<?php

namespace App\Services\Automations\Actions;

use App\Models\ClientTag;
use App\Services\Campaigns\TagService;
use App\Services\Clients\ClientService;

/** Removes a client tag via the shared TagService. */
class RemoveClientTagAction extends BaseAction
{
    public function key(): string
    {
        return 'client.remove_tag';
    }

    public function label(): string
    {
        return 'Client · Retirer un tag';
    }

    public function integration(): string
    {
        return 'clients';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'tag_name', 'label' => 'Nom du tag', 'type' => 'string'],
            ['key' => 'tag_id', 'label' => 'ID tag (optionnel)', 'type' => 'number'],
            ['key' => 'phone', 'label' => 'Téléphone', 'type' => 'string', 'hint' => '{{order.phone}}'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        $companyId = (int) ($context['company_id'] ?? 0);
        $phone = (string) ($config['phone'] ?? $context['order']['phone'] ?? $context['subject']['phone'] ?? '');
        $phoneKey = ClientService::key($phone) ?: (string) ($context['subject']['phone_key'] ?? '');

        if ($simulate) {
            return [
                'ok' => true,
                'simulated' => true,
                'output' => [
                    'would_remove_tag' => $config['tag_name'] ?? $config['tag_id'] ?? null,
                    'phone_key' => $phoneKey,
                ],
            ];
        }

        abort_if($companyId < 1 || $phoneKey === '', 422, 'company_id / phone_key manquants pour remove_tag.');

        /** @var TagService $tags */
        $tags = app(TagService::class);
        $tagId = (int) ($config['tag_id'] ?? 0);
        if ($tagId < 1 && ! empty($config['tag_name'])) {
            $tagId = (int) (ClientTag::query()->forCompany($companyId)->where('name', $config['tag_name'])->value('id') ?? 0);
        }
        abort_if($tagId < 1, 422, 'Tag introuvable.');
        $tags->remove($companyId, $phoneKey, $tagId);

        return [
            'ok' => true,
            'simulated' => false,
            'output' => ['tag_id' => $tagId, 'phone_key' => $phoneKey],
        ];
    }
}
