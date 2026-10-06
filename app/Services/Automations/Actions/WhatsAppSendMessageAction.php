<?php

namespace App\Services\Automations\Actions;

/** Stub — wired to WhatsApp module later via AutomationRegistry::registerAction. */
class WhatsAppSendMessageAction extends BaseAction
{
    public function key(): string
    {
        return 'whatsapp.send_message';
    }

    public function label(): string
    {
        return 'WhatsApp · Envoyer un message';
    }

    public function integration(): string
    {
        return 'whatsapp';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'account_id', 'label' => 'Compte expéditeur', 'type' => 'number'],
            ['key' => 'to', 'label' => 'Destinataire', 'type' => 'string', 'hint' => '{{order.phone}}'],
            ['key' => 'template', 'label' => 'Template Meta', 'type' => 'string'],
            ['key' => 'language', 'label' => 'Langue', 'type' => 'string', 'default' => 'fr'],
            ['key' => 'body', 'label' => 'Message libre', 'type' => 'textarea'],
            ['key' => 'variables', 'label' => 'Variables template (JSON)', 'type' => 'json'],
        ];
    }

    public function handle(array $config, array $context, bool $simulate = false): array
    {
        return $this->stub($config, $simulate, 'WhatsApp send_message (stub — brancher WhatsAppCloudClient)');
    }
}
