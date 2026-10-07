<?php

namespace App\Http\Controllers\Api\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppTemplate;
use App\Services\Campaigns\AudienceQueryBuilder;
use App\Services\Campaigns\CampaignLauncher;
use App\Services\Campaigns\CampaignStatsService;
use App\Services\Campaigns\CampaignVariableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WhatsAppCampaignController extends Controller
{
    public function __construct(
        protected CampaignStatsService $stats,
        protected AudienceQueryBuilder $audience,
        protected CampaignLauncher $launcher,
        protected CampaignVariableResolver $variables,
    ) {}

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->resolveCompanyId();
    }

    protected function company(Request $request): Company
    {
        return Company::query()->findOrFail($this->companyId($request));
    }

    protected function findOwned(Request $request, int $id): WhatsAppCampaign
    {
        return WhatsAppCampaign::query()
            ->forCompany($this->companyId($request))
            ->whereKey($id)
            ->firstOrFail();
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->stats->companyCounters($this->companyId($request))]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $q = WhatsAppCampaign::query()
            ->forCompany($this->companyId($request))
            ->with(['account', 'template', 'creator'])
            ->latest('id');

        if (! ($f['archived'] ?? false)) {
            $q->whereNull('archived_at')->where('status', '!=', WhatsAppCampaign::STATUS_ARCHIVED);
        }
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['search'])) {
            $s = '%'.trim($f['search']).'%';
            $q->where('name', 'like', $s);
        }

        $page = $q->paginate($f['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (WhatsAppCampaign $c) => $c->toApiArray())->values(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        $c->load(['account', 'template', 'creator', 'exclusionReasons']);
        $this->stats->refresh($c);

        return response()->json(['data' => $c->fresh(['account', 'template', 'creator', 'exclusionReasons'])->toApiArray(true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $companyId = $this->companyId($request);
        $this->assertAccountTemplate($companyId, $data);

        $campaign = WhatsAppCampaign::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => WhatsAppCampaign::STATUS_DRAFT,
            'whatsapp_account_id' => $data['whatsapp_account_id'] ?? null,
            'whatsapp_template_id' => $data['whatsapp_template_id'] ?? null,
            'audience_definition' => $data['audience_definition'] ?? ['logic' => 'and', 'rules' => []],
            'variable_mapping' => $data['variable_mapping'] ?? [],
            'manual_phone_keys' => $data['manual_phone_keys'] ?? [],
            'exclusion_phone_keys' => $data['exclusion_phone_keys'] ?? [],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $campaign->load(['account', 'template', 'creator'])->toApiArray(true)], 201);
    }

    public function update(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        abort_unless($c->isEditable(), 422, 'Cette campagne ne peut plus être modifiée.');

        $data = $this->validated($request, partial: true);
        $this->assertAccountTemplate($this->companyId($request), array_merge([
            'whatsapp_account_id' => $c->whatsapp_account_id,
            'whatsapp_template_id' => $c->whatsapp_template_id,
        ], $data));

        foreach (['name', 'whatsapp_account_id', 'whatsapp_template_id', 'audience_definition', 'variable_mapping', 'manual_phone_keys', 'exclusion_phone_keys', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $c->{$key} = $data[$key];
            }
        }
        $c->updated_by = $request->user()->id;
        $c->save();

        return response()->json(['data' => $c->fresh(['account', 'template', 'creator'])->toApiArray(true)]);
    }

    public function duplicate(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        $copy = WhatsAppCampaign::query()->create([
            'company_id' => $c->company_id,
            'name' => 'Copie — '.$c->name,
            'description' => $c->description,
            'status' => WhatsAppCampaign::STATUS_DRAFT,
            'whatsapp_account_id' => $c->whatsapp_account_id,
            'whatsapp_template_id' => $c->whatsapp_template_id,
            'audience_definition' => $c->audience_definition,
            'variable_mapping' => $c->variable_mapping,
            'manual_phone_keys' => $c->manual_phone_keys,
            'exclusion_phone_keys' => $c->exclusion_phone_keys,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $copy->load(['account', 'template', 'creator'])->toApiArray(true)], 201);
    }

    public function archive(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        abort_if($c->status === WhatsAppCampaign::STATUS_RUNNING, 422, 'Suspendez la campagne avant de l’archiver.');
        $c->forceFill([
            'status' => WhatsAppCampaign::STATUS_ARCHIVED,
            'archived_at' => now(),
            'updated_by' => $request->user()->id,
        ])->save();

        return response()->json(['data' => $c->toApiArray(), 'message' => 'Campagne archivée.']);
    }

    public function pause(Request $request, int $campaign): JsonResponse
    {
        $c = $this->launcher->pause($this->findOwned($request, $campaign), $request->user());

        return response()->json(['data' => $c->toApiArray(), 'message' => 'Campagne suspendue.']);
    }

    public function resume(Request $request, int $campaign): JsonResponse
    {
        $c = $this->launcher->resume($this->findOwned($request, $campaign), $request->user());

        return response()->json(['data' => $c->toApiArray(), 'message' => 'Campagne reprise.']);
    }

    public function audiencePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience_definition' => ['nullable', 'array'],
            'manual_phone_keys' => ['nullable', 'array'],
            'exclusion_phone_keys' => ['nullable', 'array'],
            'exclude_recently_contacted' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $company = $this->company($request);
        $resolved = $this->audience->resolveWithExclusions(
            $company,
            $data['audience_definition'] ?? null,
            $data['manual_phone_keys'] ?? [],
            $data['exclusion_phone_keys'] ?? [],
            (bool) ($data['exclude_recently_contacted'] ?? false)
        );

        $included = collect($resolved['included']);
        $perPage = $data['per_page'] ?? 25;
        $page = $data['page'] ?? 1;
        $slice = $included->forPage($page, $perPage)->values();

        $warning = $this->audience->recentlyContactedWarning(
            $company,
            $included->pluck('phone_key')->all()
        );

        return response()->json([
            'data' => [
                'included_count' => $resolved['included_count'],
                'excluded_count' => $resolved['excluded_count'],
                'excluded_reasons' => $resolved['excluded'],
                'recently_contacted' => $warning,
                'clients' => $slice->map(fn ($r) => [
                    'key' => $r->phone_key,
                    'name' => $r->customer_name,
                    'phone' => $r->phone,
                    'email' => $r->email,
                    'orders' => (int) $r->orders,
                    'total' => (float) $r->total,
                ])->all(),
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $resolved['included_count'],
                    'last_page' => max(1, (int) ceil($resolved['included_count'] / $perPage)),
                ],
            ],
        ]);
    }

    public function previewMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_template_id' => ['required', 'integer'],
            'variable_mapping' => ['nullable', 'array'],
            'phone_key' => ['nullable', 'string'],
            'audience_definition' => ['nullable', 'array'],
            'manual_phone_keys' => ['nullable', 'array'],
        ]);

        $company = $this->company($request);
        $template = WhatsAppTemplate::query()
            ->where('company_id', $company->id)
            ->whereKey($data['whatsapp_template_id'])
            ->firstOrFail();

        $phoneKey = $data['phone_key'] ?? null;
        if (! $phoneKey) {
            $sample = $this->audience->audienceQuery(
                $company->id,
                $data['audience_definition'] ?? null,
                $data['manual_phone_keys'] ?? []
            )->first();
            $phoneKey = $sample?->phone_key;
        }
        abort_unless($phoneKey, 422, 'Aucun client exemple dans l’audience.');

        $resolved = $this->variables->resolve(
            $company,
            $phoneKey,
            $template->body_text,
            $data['variable_mapping'] ?? []
        );

        return response()->json([
            'data' => [
                'phone_key' => $phoneKey,
                'preview' => $resolved['preview'],
                'variables' => $resolved['variables'],
                'context' => $resolved['context'],
                'template' => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'body_text' => $template->body_text,
                ],
            ],
        ]);
    }

    public function confirmSummary(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        $company = $this->company($request);
        $resolved = $this->audience->resolveWithExclusions(
            $company,
            $c->audience_definition,
            $c->manual_phone_keys ?? [],
            $c->exclusion_phone_keys ?? [],
            (bool) $request->boolean('exclude_recently_contacted')
        );
        $warning = $this->audience->recentlyContactedWarning(
            $company,
            collect($resolved['included'])->pluck('phone_key')->all()
        );

        return response()->json([
            'data' => [
                'campaign' => $c->load(['account', 'template'])->toApiArray(true),
                'timezone' => $company->timezoneOrDefault(),
                'included_count' => $resolved['included_count'],
                'excluded_count' => $resolved['excluded_count'],
                'excluded_reasons' => $resolved['excluded'],
                'recently_contacted' => $warning,
            ],
        ]);
    }

    public function send(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        $data = $request->validate([
            'mode' => ['required', 'in:now,schedule,draft'],
            'scheduled_at' => ['nullable', 'date'],
            'exclude_recently_contacted' => ['nullable', 'boolean'],
            'confirm' => ['required', 'accepted'],
        ]);

        if ($data['mode'] === 'draft') {
            $c->forceFill([
                'status' => WhatsAppCampaign::STATUS_DRAFT,
                'scheduled_at' => null,
                'updated_by' => $request->user()->id,
            ])->save();

            return response()->json(['data' => $c->toApiArray(), 'message' => 'Brouillon enregistré.']);
        }

        if ($data['mode'] === 'schedule') {
            abort_unless(! empty($data['scheduled_at']), 422, 'Date/heure de programmation requise.');
            $company = $this->company($request);
            $at = Carbon::parse($data['scheduled_at'], $company->timezoneOrDefault())->utc();
            $c = $this->launcher->schedule($c, $at, $request->user());

            return response()->json(['data' => $c->toApiArray(), 'message' => 'Campagne programmée.']);
        }

        $c = $this->launcher->launch($c, $request->user(), (bool) ($data['exclude_recently_contacted'] ?? false));

        return response()->json(['data' => $c->toApiArray(true), 'message' => 'Campagne lancée.']);
    }

    public function recipients(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        $f = $request->validate([
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $q = $c->recipients()->latest('id');
        if (! empty($f['status'])) {
            if ($f['status'] === 'sent') {
                $q->whereIn('status', ['sent', 'delivered', 'read']);
            } else {
                $q->where('status', $f['status']);
            }
        }
        if (! empty($f['search'])) {
            $s = '%'.trim($f['search']).'%';
            $q->where(function ($w) use ($s) {
                $w->where('customer_name', 'like', $s)
                    ->orWhere('phone', 'like', $s)
                    ->orWhere('phone_key', 'like', $s);
            });
        }

        $page = $q->paginate($f['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (WhatsAppCampaignRecipient $r) => $r->toApiArray())->values(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function retryFailed(Request $request, int $campaign): JsonResponse
    {
        $c = $this->findOwned($request, $campaign);
        abort_unless(in_array($c->status, [WhatsAppCampaign::STATUS_COMPLETED, WhatsAppCampaign::STATUS_PAUSED, WhatsAppCampaign::STATUS_RUNNING, WhatsAppCampaign::STATUS_ERROR], true), 422, 'Retraitement impossible.');

        $updated = $c->recipients()
            ->where('status', WhatsAppCampaignRecipient::STATUS_FAILED)
            ->where('retriable', true)
            ->update([
                'status' => WhatsAppCampaignRecipient::STATUS_PENDING,
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
            ]);

        if ($c->status !== WhatsAppCampaign::STATUS_RUNNING) {
            $c->forceFill(['status' => WhatsAppCampaign::STATUS_RUNNING, 'paused_at' => null])->save();
        }
        $this->launcher->dispatchBatches($c->fresh());

        return response()->json(['message' => "$updated destinataire(s) remis en file.", 'retried' => $updated]);
    }

    public function searchClients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $query = $this->audience->baseQuery();
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function ($w) use ($q, $digits) {
                $w->where('lo.customer_name', 'like', "%$q%")
                    ->orWhere('lo.email', 'like', "%$q%")
                    ->orWhere('c.phone_key', 'like', "%$q%");
                if (strlen($digits) >= 4) {
                    $w->orWhere('c.phone_key', 'like', '%'.$digits.'%');
                }
            });
        }
        $page = $query->orderByDesc('c.last_at')->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => [
                'key' => $r->phone_key,
                'name' => $r->customer_name,
                'phone' => $r->phone,
                'email' => $r->email,
            ])->values(),
            'meta' => ['total' => $page->total()],
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $accounts = WhatsAppAccount::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsAppAccount $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'phone_number' => $a->display_phone_number ?: $a->phone_number,
                'status' => $a->status,
                'connected' => $a->isConnected(),
            ]);

        return response()->json([
            'data' => [
                'timezone' => $company->timezoneOrDefault(),
                'vehicles_enabled' => $company->vehiclesEnabled(),
                'campaign_settings' => [
                    'max_campaigns_per_client' => $company->campaignSetting('max_campaigns_per_client', 3),
                    'max_campaigns_window_days' => $company->campaignSetting('max_campaigns_window_days', 7),
                    'exclude_refused_consent' => $company->campaignSetting('exclude_refused_consent', true),
                    'require_allowed_consent_for_marketing' => $company->campaignSetting('require_allowed_consent_for_marketing', false),
                ],
                'accounts' => $accounts,
                'variable_sources' => [
                    ['key' => 'client_name', 'label' => 'Nom du client'],
                    ['key' => 'vehicle_brand', 'label' => 'Marque véhicule'],
                    ['key' => 'vehicle_model', 'label' => 'Modèle véhicule'],
                    ['key' => 'vehicle_year', 'label' => 'Année véhicule'],
                    ['key' => 'product_name', 'label' => 'Dernier produit'],
                    ['key' => 'order_number', 'label' => 'N° commande'],
                    ['key' => 'company_name', 'label' => 'Nom société'],
                    ['key' => 'promo_code', 'label' => 'Code promo (fixe)'],
                    ['key' => 'fixed', 'label' => 'Valeur fixe'],
                ],
            ],
        ]);
    }

    public function templatesForAccount(Request $request, int $account): JsonResponse
    {
        $companyId = $this->companyId($request);
        WhatsAppAccount::query()->where('company_id', $companyId)->whereKey($account)->firstOrFail();

        $templates = WhatsAppTemplate::query()
            ->where('company_id', $companyId)
            ->where('whatsapp_account_id', $account)
            ->whereRaw('UPPER(status) = ?', ['APPROVED'])
            ->orderBy('name')
            ->get()
            ->map(fn (WhatsAppTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'status' => $t->status,
                'category' => $t->category ?? null,
                'body_text' => $t->body_text,
                'variables_count' => $t->variables_count,
                'components' => $t->components,
            ]);

        return response()->json(['data' => $templates]);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $nameRule = $partial ? ['sometimes', 'string', 'max:160'] : ['required', 'string', 'max:160'];

        return $request->validate([
            'name' => $nameRule,
            'description' => ['nullable', 'string', 'max:2000'],
            'whatsapp_account_id' => ['nullable', 'integer'],
            'whatsapp_template_id' => ['nullable', 'integer'],
            'audience_definition' => ['nullable', 'array'],
            'variable_mapping' => ['nullable', 'array'],
            'manual_phone_keys' => ['nullable', 'array'],
            'manual_phone_keys.*' => ['string', 'max:32'],
            'exclusion_phone_keys' => ['nullable', 'array'],
            'exclusion_phone_keys.*' => ['string', 'max:32'],
        ]);
    }

    protected function assertAccountTemplate(int $companyId, array $data): void
    {
        if (! empty($data['whatsapp_account_id'])) {
            WhatsAppAccount::query()->where('company_id', $companyId)->whereKey($data['whatsapp_account_id'])->firstOrFail();
        }
        if (! empty($data['whatsapp_template_id'])) {
            WhatsAppTemplate::query()->where('company_id', $companyId)->whereKey($data['whatsapp_template_id'])->firstOrFail();
        }
    }
}
