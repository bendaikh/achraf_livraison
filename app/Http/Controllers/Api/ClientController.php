<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientBlock;
use App\Models\ClientGroup;
use App\Models\ClientGroupMember;
use App\Models\ClientNote;
use App\Models\ClientVehicle;
use App\Models\Company;
use App\Models\ConfirmationStatus;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\TagService;
use App\Services\Clients\ClientService;
use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** T9 — Clients (aggregated from orders by phone), blocks, notes, segments and manual groups. */
class ClientController extends Controller
{
    public function __construct(
        private ClientService $clients,
        private TagService $tags,
        private ConsentService $consent,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'tab' => ['nullable', 'in:all,blocked,segment,group'],
            'segment' => ['nullable', 'string'],
            'group_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $page = $this->clients->listQuery($f)->paginate($f['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => $this->clients->payload($r))->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Tab counters (real data) + segment/group definitions. */
    public function summary(): JsonResponse
    {
        $segments = collect(config('client_segments.segments'))->map(fn ($s, $k) => [
            'key' => $k, 'label' => $s['label'], 'color' => $s['color'], 'description' => $s['description'],
            'count' => $this->clients->applySegment($this->clients->aggregateQuery(), $k)->count(),
        ])->values();
        $groups = ClientGroup::query()->withCount('members')->orderBy('name')->get()
            ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'color' => $g->color, 'description' => $g->description, 'count' => $g->members_count]);

        return response()->json([
            'all' => $this->clients->aggregateQuery()->count(),
            'blocked' => ClientBlock::query()->active()->distinct('phone_key')->count('phone_key'),
            'segments' => $segments,
            'groups' => $groups,
            'block_reasons' => config('client_segments.block_reasons'),
        ]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $row = $this->clients->find($key);
        abort_unless($row, 404, 'Client introuvable.');

        $companyId = (int) $request->user()->resolveCompanyId();
        $company = Company::query()->find($companyId);
        $vehiclesEnabled = $company?->vehiclesEnabled() ?? false;

        $orders = Order::query()->where('phone_key', $key)->latest('id')->get();
        $orderIds = $orders->pluck('id');
        $variants = PhoneNormalizer::matchVariants($key);

        return response()->json([
            'client' => $this->clients->payload($row),
            'addresses' => $this->clients->addresses($key),
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'reference' => $o->reference(),
                'date' => ($o->shopify_created_at ?? $o->created_at)?->toIso8601String(),
                'amount' => (float) $o->total_price,
                'product_name' => $o->productName(),
                'confirmation_status' => $o->confirmation_status,
                'confirmation_label' => ConfirmationStatus::labelFor($o->confirmation_status, $o->company_id),
                'confirmation_color' => ConfirmationStatus::colorFor($o->confirmation_status, $o->company_id),
                'delivery_label' => $o->deliveryStatusLabel(),
                'delivery_color' => $o->deliveryStatusColor(),
                'delivery_category' => $o->deliveryStatusDefinition()?->category,
                'carrier' => $o->carrier,
            ])->values(),
            'calls' => OrderCall::query()->whereIn('order_id', $orderIds)->with('user:id,name', 'order:id,order_number,name,source')->latest('called_at')->limit(50)->get()
                ->map(fn ($c) => $c->toPayload() + ['order_id' => $c->order_id, 'order_reference' => $c->order?->reference()])->values(),
            'conversations' => WhatsAppConversation::query()
                ->where(fn ($q) => $q->whereIn('contact_phone', $variants)->orWhereIn('contact_wa_id', $variants))
                ->latest('last_message_at')->limit(10)->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->contact_name, 'preview' => $c->last_message_preview, 'last_message_at' => $c->last_message_at?->toIso8601String(), 'unread' => (int) $c->unread_count])->values(),
            'notes' => ClientNote::query()->where('phone_key', $key)->with('user:id,name')->latest()->get()
                ->map(fn ($n) => ['id' => $n->id, 'body' => $n->body, 'user_name' => $n->user?->name, 'created_at' => $n->created_at?->toIso8601String()])->values(),
            'blocks' => ClientBlock::query()->where('phone_key', $key)->with('blocker:id,name', 'unblocker:id,name')->latest('blocked_at')->get()->map->toPayload()->values(),
            'groups' => ClientGroup::query()->whereIn('id', ClientGroupMember::query()->where('phone_key', $key)->select('client_group_id'))->get(['id', 'name', 'color']),
            'tags' => $this->tags->tagsForClient($companyId, $key)->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
            ])->values(),
            'whatsapp_consent' => $this->consent->getOrUnknown($companyId, $key)->toApiArray(),
            'vehicles' => $vehiclesEnabled
                ? ClientVehicle::query()->forCompany($companyId)->where('phone_key', $key)
                    ->orderByDesc('is_primary')->orderBy('id')->get()->map->toApiArray()->values()
                : [],
            'vehicles_enabled' => $vehiclesEnabled,
            'campaigns' => WhatsAppCampaignRecipient::query()
                ->where('company_id', $companyId)
                ->where('phone_key', $key)
                ->whereNotIn('status', [WhatsAppCampaignRecipient::STATUS_EXCLUDED])
                ->with('campaign:id,name,whatsapp_template_id')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (WhatsAppCampaignRecipient $r) => [
                    'id' => $r->id,
                    'campaign_id' => $r->whatsapp_campaign_id,
                    'campaign_name' => $r->campaign?->name,
                    'status' => $r->status,
                    'sent_at' => $r->sent_at?->toIso8601String(),
                    'delivered_at' => $r->delivered_at?->toIso8601String(),
                    'read_at' => $r->read_at?->toIso8601String(),
                    'error_message' => $r->error_message,
                    'preview_body' => $r->preview_body,
                ])->values(),
        ]);
    }

    public function block(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:120'], 'comment' => ['nullable', 'string', 'max:2000']], ['reason.required' => 'Le motif est obligatoire.']);
        $row = $this->clients->find($key);
        abort_unless($row, 404, 'Client introuvable.');
        if (ClientBlock::activeFor($key)) {
            return response()->json(['message' => 'Ce client est déjà bloqué.'], 422);
        }
        ClientBlock::create($data + [
            'phone_key' => $key, 'customer_name' => $row->customer_name, 'blocked_by' => $request->user()->id,
            'blocked_at' => now(), 'company_id' => $request->user()->resolveCompanyId(),
        ]);

        return response()->json(['message' => 'Client bloqué. Une alerte s’affichera sur ses prochaines commandes.']);
    }

    public function unblock(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $block = ClientBlock::query()->active()->where('phone_key', $key)->first();
        abort_unless($block, 404, 'Ce client n’est pas bloqué.');
        $block->update(['unblocked_at' => now(), 'unblocked_by' => $request->user()->id, 'unblock_reason' => $data['reason'] ?? null]);

        return response()->json(['message' => 'Client débloqué.']);
    }

    public function addNote(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        abort_unless($this->clients->find($key), 404, 'Client introuvable.');
        ClientNote::create(['phone_key' => $key, 'body' => $data['body'], 'user_id' => $request->user()->id, 'company_id' => $request->user()->resolveCompanyId()]);

        return response()->json(['message' => 'Note ajoutée.'], 201);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $this->validateGroup($request);
        $g = ClientGroup::create($data + ['created_by' => $request->user()->id, 'company_id' => $request->user()->resolveCompanyId()]);

        return response()->json(['message' => 'Groupe créé.', 'data' => $g], 201);
    }

    public function updateGroup(Request $request, ClientGroup $group): JsonResponse
    {
        $group->update($this->validateGroup($request));

        return response()->json(['message' => 'Groupe enregistré.', 'data' => $group]);
    }

    public function destroyGroup(ClientGroup $group): JsonResponse
    {
        $group->delete();

        return response()->json(['message' => 'Groupe supprimé (les clients et leurs commandes ne sont pas touchés).']);
    }

    public function addMembers(Request $request, ClientGroup $group): JsonResponse
    {
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:500'], 'keys.*' => ['string', 'max:32']]);
        $existing = Order::query()->whereIn('phone_key', $data['keys'])->distinct()->pluck('phone_key');
        $n = 0;
        foreach ($existing as $key) {
            $m = ClientGroupMember::firstOrCreate(['client_group_id' => $group->id, 'phone_key' => $key], ['added_by' => $request->user()->id]);
            $n += $m->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['message' => "{$n} client(s) ajouté(s) au groupe « {$group->name} »."]);
    }

    public function removeMember(ClientGroup $group, string $key): JsonResponse
    {
        ClientGroupMember::query()->where('client_group_id', $group->id)->where('phone_key', $key)->delete();

        return response()->json(['message' => 'Client retiré du groupe.']);
    }

    private function validateGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['name.required' => 'Le nom du groupe est obligatoire.']);
    }
}
