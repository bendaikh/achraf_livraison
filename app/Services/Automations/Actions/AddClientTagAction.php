<?php

namespace App\Services\Automations\Actions;

use App\Services\Campaigns\TagService;
use App\Services\Clients\ClientService;

/** Adds a client tag via the shared TagService (campaigns + automations). */
class AddClientTagAction extends BaseAction
{
    public function key(): string
    {
        return 'client.add_tag';
    }

    public function label(): string
    {
        return 'Client · Ajouter un tag';
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
                    'would_add_tag' => $config['tag_name'] ?? $config['tag_id'] ?? null,
                    'phone_key' => $phoneKey,
                ],
            ];
        }

        abort_if($companyId < 1 || $phoneKey === '', 422, 'company_id / phone_key manquants pour add_tag.');

        /** @var TagService $tags */
        $tags = app(TagService::class);
        if (! empty($config['tag_id'])) {
            $assignment = $tags->assign($companyId, $phoneKey, (int) $config['tag_id']);
        } else {
            abort_unless(! empty($config['tag_name']), 422, 'tag_name requis.');
            $assignment = $tags->assignByName($companyId, $phoneKey, (string) $config['tag_name']);
        }

        return [
            'ok' => true,
            'simulated' => false,
            'output' => [
                'tag_id' => $assignment->client_tag_id,
                'phone_key' => $phoneKey,
            ],
        ];
    }
}
