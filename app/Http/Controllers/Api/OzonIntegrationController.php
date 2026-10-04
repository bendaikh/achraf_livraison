<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryStatus;
use App\Models\OzonApiLog;
use App\Models\OzonCity;
use App\Models\OzonCityMapping;
use App\Models\OzonDeliveryNote;
use App\Models\OzonSetting;
use App\Models\OzonShipment;
use App\Models\Setting;
use App\Services\Ozon\OzonCityService;
use App\Services\Ozon\OzonClient;
use App\Services\Ozon\OzonException;
use App\Services\Ozon\OzonShipmentService;
use App\Services\Ozon\OzonStatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Intégrations → Transporteurs → Ozon Express (settings of the signed-in user's company) and
 * Paramètres → Transporteurs → Ozon (mapping villes / statuts). The API key is write-only:
 * the browser only ever receives a masked hint.
 */
class OzonIntegrationController extends Controller
{
    public function __construct(protected OzonCityService $cities) {}

    protected function settings(Request $request): OzonSetting
    {
        return OzonSetting::forCompany($request->user()->resolveCompanyId());
    }

    protected function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Seuls les administrateurs peuvent configurer Ozon Express.');
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->settings($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'customer_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'default_stock' => ['sometimes', Rule::in([0, 1, '0', '1'])],
            'stock_by_type' => ['sometimes', 'array'],
            'stock_by_type.*' => ['nullable', Rule::in([0, 1, '0', '1', ''])],
            'default_open' => ['sometimes', 'boolean'],
            'default_fragile' => ['sometimes', 'boolean'],
            'nature_mode' => ['sometimes', Rule::in(['products', 'fixed', 'none'])],
            'nature_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'send_products' => ['sometimes', 'boolean'],
            'send_note' => ['sometimes', 'boolean'],
            'auto_sync' => ['sometimes', 'boolean'],
            'status_mapping' => ['sometimes', 'array'],
            'status_mapping.*.raw' => ['required_with:status_mapping', 'string', 'max:120'],
            'status_mapping.*.code' => ['nullable', 'string', 'max:60'],
            'bulk_enabled' => ['sometimes', 'boolean'],
        ], [], ['customer_id' => 'ID client', 'api_key' => 'clé API']);

        $key = trim((string) ($data['api_key'] ?? ''));
        if ($request->boolean('clear_api_key')) {
            $settings->api_key = null;
        } elseif ($key !== '') {
            $settings->api_key = $key;
        }
        if (array_key_exists('customer_id', $data)) {
            $data['customer_id'] = trim((string) $data['customer_id']) ?: null;
        }
        if (array_key_exists('stock_by_type', $data)) {
            $stock = [];
            foreach (array_keys(OzonSetting::PARCEL_TYPES) as $type) {
                $v = $data['stock_by_type'][$type] ?? null;
                $stock[$type] = $v === null || $v === '' ? null : (int) $v;
            }
            $data['stock_by_type'] = $stock;
        }
        if (array_key_exists('status_mapping', $data)) {
            $valid = DeliveryStatus::query()->pluck('code')->all();
            $mapping = [];
            foreach ($data['status_mapping'] as $row) {
                $raw = trim((string) $row['raw']);
                if ($raw !== '') {
                    $mapping[$raw] = ($row['code'] ?? null) && in_array($row['code'], $valid, true) ? $row['code'] : null;
                }
            }
            $data['status_mapping'] = $mapping;
        }
        if (array_key_exists('bulk_enabled', $data)) {
            Setting::setValue('ozon_bulk_enabled', (bool) $data['bulk_enabled']);
        }
        if (($data['customer_id'] ?? $settings->customer_id) !== $settings->customer_id || $key !== '' || $request->boolean('clear_api_key')) {
            $settings->forceFill(['last_test_ok' => null, 'last_tested_at' => null, 'last_test_message' => null]);
        }
        unset($data['api_key'], $data['clear_api_key'], $data['bulk_enabled']);
        $settings->fill($data);
        if ($settings->enabled && ! $settings->hasCredentials()) {
            return response()->json(['message' => 'Renseignez l’ID client et la clé API avant d’activer Ozon Express.', 'errors' => ['customer_id' => ['ID client et clé API obligatoires pour activer l’intégration.']]], 422);
        }
        $settings->save();

        return response()->json(['message' => 'Paramètres Ozon Express enregistrés.', 'data' => $this->payload($settings->fresh())]);
    }

    /** « Tester la connexion » with the typed values (not saved) or the saved ones. */
    public function test(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $data = $request->validate(['customer_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'api_key' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $customerId = trim((string) ($data['customer_id'] ?? '')) ?: $settings->customer_id;
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: $settings->api_key;
        if (blank($customerId) || blank($apiKey)) {
            return response()->json(['ok' => false, 'message' => 'Renseignez l’ID client et la clé API fournis par Ozon Express avant de tester.'], 422);
        }
        $client = new OzonClient($customerId, $apiKey);
        $started = microtime(true);
        try {
            $client->checkCredentials();
            $ok = true;
            $message = 'Connexion réussie à Ozon Express en '.(int) round((microtime(true) - $started) * 1000).' ms : identifiants acceptés.';
        } catch (OzonException $e) {
            $ok = false;
            $message = $client->redact($e->getMessage());
            if ($customerId === $settings->customer_id && $apiKey === $settings->api_key) {
                OzonShipmentService::for($settings)->logError('test', $e, $request->user());
            }
        }
        if ($customerId === $settings->customer_id && $apiKey === $settings->api_key) {
            $settings->forceFill(['last_tested_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => mb_substr($message, 0, 500)])->save();
        }

        return response()->json(['ok' => $ok, 'message' => $message, 'data' => $this->payload($settings->fresh())], $ok ? 200 : 422);
    }

    /** GET /cities (public) → ozon_cities, then auto-match the order cities. */
    public function syncCities(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        try {
            $res = $this->cities->sync();
        } catch (OzonException $e) {
            OzonShipmentService::for($settings)->logError('cities', $e, $request->user(), ['context' => []]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $stats = $this->cities->autoMatchOrderCities($settings->company_id);

        return response()->json([
            'message' => "{$res['count']} villes Ozon synchronisées. Villes des commandes : {$stats['matched']} associée(s), {$stats['unmatched']} à associer.",
            'data' => $this->payload($settings->fresh()),
        ]);
    }

    /** Official cities for a select (search on name / ref). */
    public function cities(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $key = OzonCityService::key($q);
        $rows = OzonCity::query()->where('active', true)
            ->when($key !== '', fn ($w) => $w->where(fn ($x) => $x->where('name_key', 'like', "%{$key}%")->orWhere('ref', 'like', "{$q}%")))
            ->orderByRaw('length(name_key)')->orderBy('name')->limit(40)->get();

        return response()->json(['data' => $rows->map->toOption()->values()]);
    }

    /** Mapping villes: every order city (with order count) and its Ozon city. */
    public function cityMappings(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $mappings = OzonCityMapping::query()->where('company_id', $settings->company_id)->with('city')->get()->keyBy('city_key');
        $rows = [];
        foreach ($this->cities->orderCities() as $label => $count) {
            $key = OzonCityService::key($label);
            $m = $mappings->get($key);
            if (! $m) {
                $this->cities->resolve($settings->company_id, $label);
                $m = OzonCityMapping::query()->where('company_id', $settings->company_id)->where('city_key', $key)->with('city')->first();
            }
            $rows[] = $this->mappingRow($label, $count, $m);
            $mappings->forget($key);
        }
        foreach ($mappings as $m) {
            $rows[] = $this->mappingRow($m->city_label, 0, $m);
        }
        $filter = $request->query('filter');
        if ($filter === 'unmatched') {
            $rows = array_values(array_filter($rows, fn ($r) => ! $r['ozon_city']));
        }

        return response()->json([
            'data' => $rows,
            'stats' => ['total' => count($rows), 'unmatched' => count(array_filter($rows, fn ($r) => ! $r['ozon_city']))],
            'cities_count' => OzonCity::query()->where('active', true)->count(),
        ]);
    }

    protected function mappingRow(string $label, int $count, ?OzonCityMapping $m): array
    {
        return [
            'city' => $label,
            'orders' => $count,
            'ozon_city' => $m?->city?->toOption(),
            'source' => $m?->source ?? 'auto',
            'suggestions' => $m?->city ? [] : $this->cities->suggestions($label, 3),
        ];
    }

    public function updateCityMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:160'],
            'ozon_city_id' => ['nullable', 'integer', Rule::exists('ozon_cities', 'ozon_id')],
        ]);
        $settings = $this->settings($request);
        $m = $this->cities->setMapping($settings->company_id, $data['city'], $data['ozon_city_id'] ?? null, $request->user());

        return response()->json(['message' => $m->ozon_city_id ? "« {$data['city']} » → {$m->city?->name}." : "Association de « {$data['city']} » supprimée.", 'data' => $this->mappingRow($m->city_label, 0, $m->load('city'))]);
    }

    public function autoMatch(Request $request): JsonResponse
    {
        if (! $this->cities->hasCities()) {
            return response()->json(['message' => 'Synchronisez d’abord la liste des villes Ozon.'], 422);
        }
        $stats = $this->cities->autoMatchOrderCities($this->settings($request)->company_id);

        return response()->json(['message' => "{$stats['cities']} ville(s) : {$stats['matched']} associée(s), {$stats['unmatched']} à associer manuellement."]);
    }

    /** « Synchroniser maintenant »: bulk tracking of the open parcels. */
    public function sync(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        if (! $settings->enabled || ! $settings->hasCredentials()) {
            return response()->json(['message' => 'Activez et configurez Ozon Express avant de synchroniser.'], 422);
        }
        $shipments = OzonShipment::query()->trackable()->where('company_id', $settings->company_id)->with('order')->get();
        try {
            $stats = OzonShipmentService::for($settings)->syncStatus($shipments, $request->user(), 'manuel');
        } catch (OzonException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $message = "{$stats['checked']} colis vérifié(s), {$stats['updated']} statut(s) mis à jour.";
        if ($stats['errors']) {
            $message .= ' Erreurs : '.implode(' ', array_slice(array_unique($stats['errors']), 0, 3));
        }

        return response()->json(['message' => $message, 'stats' => $stats, 'data' => $this->payload($settings->fresh())], $stats['errors'] && ! $stats['checked'] ? 422 : 200);
    }

    /** Journal des erreurs API. */
    public function logs(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $logs = OzonApiLog::query()->where('company_id', $settings->company_id)
            ->when($request->query('status') === 'open', fn ($q) => $q->where('resolved', false))
            ->with(['order', 'user'])->latest('id')->limit(200)->get();

        return response()->json(['data' => $logs->map->toSummary()->values()]);
    }

    public function retry(Request $request, OzonApiLog $log): JsonResponse
    {
        $settings = $this->settings($request);
        abort_unless((int) $log->company_id === (int) $settings->company_id, 404);
        if (! in_array($log->action, OzonApiLog::RETRYABLE, true) || $log->resolved) {
            return response()->json(['message' => 'Cette erreur ne peut pas être relancée.'], 422);
        }
        try {
            $message = OzonShipmentService::for($settings)->retry($log, $request->user());
        } catch (OzonException $e) {
            return response()->json(['message' => str_replace("\n", ' ', $e->getMessage()), 'data' => $log->fresh(['order', 'user'])->toSummary()], 422);
        }

        return response()->json(['message' => $message, 'data' => $log->fresh(['order', 'user'])->toSummary()]);
    }

    public function deliveryNotes(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $notes = OzonDeliveryNote::query()->where('company_id', $settings->company_id)->with(['items.order', 'creator'])->latest('id')->limit(100)->get();

        return response()->json(['data' => $notes->map(fn ($n) => $n->toSummary(true))->values()]);
    }

    public static function maskKey(?string $key): ?string
    {
        return blank($key) ? null : str_repeat('•', 8).mb_substr($key, -4);
    }

    protected function payload(OzonSetting $s): array
    {
        $counts = OzonShipment::query()->where('company_id', $s->company_id)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $mapping = [];
        foreach ($s->mapping() as $raw => $code) {
            $mapping[] = ['raw' => (string) $raw, 'code' => $code, 'seen' => in_array($raw, (array) ($s->seen_statuses ?? []), true)];
        }

        return [
            'enabled' => (bool) $s->enabled,
            'customer_id' => $s->customer_id,
            'has_api_key' => filled($s->api_key),
            'api_key_hint' => self::maskKey($s->api_key),
            'base_url' => OzonClient::BASE_URL.'/customers/{ID}/{CLÉ}/',
            'default_stock' => (int) $s->default_stock,
            'stock_by_type' => (object) array_replace(array_fill_keys(array_keys(OzonSetting::PARCEL_TYPES), null), (array) ($s->stock_by_type ?? [])),
            'default_open' => (bool) $s->default_open,
            'default_fragile' => (bool) $s->default_fragile,
            'nature_mode' => $s->nature_mode,
            'nature_text' => $s->nature_text,
            'send_products' => (bool) $s->send_products,
            'send_note' => (bool) $s->send_note,
            'auto_sync' => (bool) $s->auto_sync,
            'bulk_enabled' => OzonShipmentService::bulkEnabled(),
            'status_mapping' => $mapping,
            'last_tested_at' => $s->last_tested_at?->toIso8601String(),
            'last_test_ok' => $s->last_test_ok,
            'last_test_message' => $s->last_test_message,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'last_sync_message' => $s->last_sync_message,
            'missing' => $s->missingForShipping(),
            'ready' => $s->missingForShipping() === [],
            'state' => ! $s->enabled ? 'inactif' : (! $s->hasCredentials() ? 'incomplet' : ($s->last_test_ok === false ? 'erreur' : ($s->last_test_ok ? 'connecte' : 'non_teste'))),
            'cities_count' => OzonCity::query()->where('active', true)->count(),
            'cities_synced_at' => OzonCity::query()->max('updated_at'),
            'unmatched_cities' => OzonCityMapping::query()->where('company_id', $s->company_id)->whereNull('ozon_city_id')->count(),
            'open_errors' => OzonApiLog::query()->where('company_id', $s->company_id)->where('resolved', false)->count(),
            'stats' => [
                'active' => (int) ($counts[OzonShipment::STATE_CREATED] ?? 0),
                'delivered' => (int) ($counts[OzonShipment::STATE_DELIVERED] ?? 0),
                'returned' => (int) ($counts[OzonShipment::STATE_RETURNED] ?? 0),
                'cancelled' => (int) ($counts[OzonShipment::STATE_CANCELLED] ?? 0),
                'delivery_notes' => OzonDeliveryNote::query()->where('company_id', $s->company_id)->where('state', 'saved')->count(),
            ],
            'options' => [
                'parcel_types' => collect(OzonSetting::PARCEL_TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'delivery_statuses' => DeliveryStatus::query()->orderBy('sort_order')->get(['code', 'name', 'color', 'category', 'is_active'])->values(),
                'default_statuses' => array_keys(OzonStatusMap::DEFAULT_MAPPING),
            ],
        ];
    }
}
