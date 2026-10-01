<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateSyncService;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppTemplateController extends Controller
{
    protected function companyId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->resolveCompanyId();
    }

    public function index(Request $request): JsonResponse
    {
        $templates = WhatsAppTemplate::query()
            ->with('account:id,name,display_phone_number')
            ->where('company_id', $this->companyId($request))
            ->when($request->query('account_id'), fn ($q, $id) => $q->where('whatsapp_account_id', $id))
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsAppTemplate $t) => $t->toApiArray());

        return response()->json(['templates' => $templates]);
    }

    public function sync(Request $request, TemplateSyncService $sync): JsonResponse
    {
        $count = $sync->syncCompany($this->companyId($request));

        return response()->json([
            'message' => "{$count} template(s) synchronisé(s).",
            'synced' => $count,
        ]);
    }

    public function store(Request $request, WhatsAppCloudClient $client): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Accès réservé aux administrateurs.'], 403);
        }

        $data = $request->validate([
            'whatsapp_account_id' => ['required', 'integer', 'exists:whatsapp_accounts,id'],
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:16'],
            'category' => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'header' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
            'footer' => ['nullable', 'string', 'max:60'],
        ]);

        $account = WhatsAppAccount::query()
            ->where('company_id', $this->companyId($request))
            ->where('id', $data['whatsapp_account_id'])
            ->firstOrFail();

        if (! $account->isConnected()) {
            return response()->json(['message' => 'Compte WhatsApp non connecté.'], 422);
        }

        $components = [];
        if (! empty($data['header'])) {
            $components[] = [
                'type' => 'HEADER',
                'format' => 'TEXT',
                'text' => $data['header'],
            ];
        }
        $components[] = [
            'type' => 'BODY',
            'text' => $data['body'],
        ];
        if (! empty($data['footer'])) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $data['footer'],
            ];
        }

        try {
            $response = $client->createMessageTemplate($account, [
                'name' => $data['name'],
                'language' => $data['language'],
                'category' => $data['category'],
                'components' => $components,
            ]);

            $variables = 0;
            if (preg_match_all('/\{\{\d+\}\}/', $data['body'], $m)) {
                $variables = count($m[0]);
            }

            // Never mark as APPROVED locally — only Meta status counts
            $template = WhatsAppTemplate::query()->updateOrCreate(
                [
                    'company_id' => $account->company_id,
                    'waba_id' => $account->waba_id,
                    'name' => $data['name'],
                    'language' => $data['language'],
                ],
                [
                    'whatsapp_account_id' => $account->id,
                    'meta_template_id' => $response['id'] ?? null,
                    'category' => $data['category'],
                    'status' => $response['status'] ?? 'PENDING',
                    'body_text' => $data['body'],
                    'header_text' => $data['header'] ?? null,
                    'footer_text' => $data['footer'] ?? null,
                    'variables_count' => $variables,
                    'components' => $components,
                    'last_synced_at' => now(),
                ]
            );

            return response()->json([
                'message' => 'Template soumis à Meta pour approbation.',
                'template' => $template->toApiArray(),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
