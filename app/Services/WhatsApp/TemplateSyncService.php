<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Log;

class TemplateSyncService
{
    public function __construct(
        protected WhatsAppCloudClient $client,
    ) {}

    public function syncAccount(WhatsAppAccount $account): int
    {
        $templates = $this->client->listMessageTemplates($account);
        $count = 0;

        foreach ($templates as $tpl) {
            $components = $tpl['components'] ?? [];
            [$header, $body, $footer, $buttons, $variables] = $this->extractComponents($components);

            WhatsAppTemplate::query()->updateOrCreate(
                [
                    'company_id' => $account->company_id,
                    'waba_id' => $account->waba_id,
                    'name' => $tpl['name'],
                    'language' => $tpl['language'] ?? 'fr',
                ],
                [
                    'whatsapp_account_id' => $account->id,
                    'meta_template_id' => $tpl['id'] ?? null,
                    'category' => $tpl['category'] ?? null,
                    'status' => $tpl['status'] ?? 'PENDING',
                    'body_text' => $body,
                    'header_text' => $header,
                    'footer_text' => $footer,
                    'variables_count' => $variables,
                    'components' => $components,
                    'buttons' => $buttons,
                    'last_synced_at' => now(),
                ]
            );
            $count++;
        }

        $account->forceFill(['last_synced_at' => now()])->save();

        return $count;
    }

    public function syncCompany(int $companyId): int
    {
        $total = 0;
        $accounts = WhatsAppAccount::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('status', WhatsAppAccount::STATUS_CONNECTED)
            ->get();

        foreach ($accounts as $account) {
            try {
                $total += $this->syncAccount($account);
            } catch (\Throwable $e) {
                Log::error('WhatsApp template sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $total;
    }

    /**
     * @return array{0:?string,1:?string,2:?string,3:array,4:int}
     */
    protected function extractComponents(array $components): array
    {
        $header = null;
        $body = null;
        $footer = null;
        $buttons = [];
        $variables = 0;

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type === 'HEADER' && ($component['format'] ?? '') === 'TEXT') {
                $header = $component['text'] ?? null;
            }
            if ($type === 'BODY') {
                $body = $component['text'] ?? null;
                if (is_string($body) && preg_match_all('/\{\{\d+\}\}/', $body, $m)) {
                    $variables = count($m[0]);
                }
            }
            if ($type === 'FOOTER') {
                $footer = $component['text'] ?? null;
            }
            if ($type === 'BUTTONS') {
                $buttons = $component['buttons'] ?? [];
            }
        }

        return [$header, $body, $footer, $buttons, $variables];
    }
}
