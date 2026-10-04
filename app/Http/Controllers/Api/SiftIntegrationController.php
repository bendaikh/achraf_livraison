<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SiftApiLog;
use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Models\SiftWebhookEvent;
use App\Services\Sift\SiftClient;
use App\Services\Sift\SiftException;
use App\Services\Sift\SiftShipmentService;
use App\Services\Sift\SiftStatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Intégrations → Transporteurs → Sift.ma and Paramètres → Transporteurs → Sift (status mapping).
 * The API key is write-only (masked hint only). The webhook secret is shown on explicit request
 * because the admin has to paste it into Sift.
 */
class SiftIntegrationController extends Controller
{
    protected function settings(Request $request): SiftSetting
    {
        return SiftSetting::forCompany($request->user()->resolveCompanyId());
    }

    protected function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Seuls les administrateurs peuvent configurer Sift.ma.');
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
            'base_url' => ['sometimes', 'nullable', 'url:https', 'max:190'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'auth_mode' => ['sometimes', Rule::in(['bearer', 'x-api-key'])],
            'default_allow_open' => ['sometimes', 'boolean'],
            'items_mode' => ['sometimes', Rule::in(['manual', 'sku'])],
            'send_note' => ['sometimes', 'boolean'],
            'waybill_format' => ['sometimes', Rule::in(array_keys(SiftSetting::WAYBILL_FORMATS))],
            'auto_sync' => ['sometimes', 'boolean'],
            'status_mapping' => ['sometimes', 'array'],
            'status_mapping.*.raw' => ['required_with:status_mapping', 'string', 'max:120'],
            'status_mapping.*.code' => ['nullable', 'string', 'max:60'],
            'bulk_enabled' => ['sometimes', 'boolean'],
        ], [], ['api_key' => 'clé API', 'base_url' => 'URL de base']);

        $key = trim((string) ($data['api_key'] ?? ''));
        $credentialsChanged = false;
        if ($request->boolean('clear_api_key')) {
            $settings->api_key = null;
            $credentialsChanged = true;
        } elseif ($key !== '') {
            $settings->api_key = $key;
            $credentialsChanged = true;
        }
        if (array_key_exists('base_url', $data)) {
            $data['base_url'] = rtrim(trim((string) $data['base_url']), '/') ?: SiftClient::BASE_URL;
            $credentialsChanged = $credentialsChanged || $data['base_url'] !== $settings->base_url;
        }
        if (isset($data['auth_mode']) && $data['auth_mode'] !== $settings->auth_mode) {
            $credentialsChanged = true;
        }
        if (array_key_exists('status_mapping', $data)) {
            $valid = DeliveryStatus::query()->pluck('code')->all();
            $mapping = [];
            foreach ($data['status_mapping'] as $row) {
                $raw = SiftStatusMap::key((string) $row['raw']);
                if ($raw !== '') {
                    $mapping[$raw] = ($row['code'] ?? null) && in_array($row['code'], $valid, true) ? $row['code'] : null;
                }
            }
            $data['status_mapping'] = $mapping;
        }
        if (array_key_exists('bulk_enabled', $data)) {
            Setting::setValue('sift_bulk_enabled', (bool) $data['bulk_enabled']);
        }
        if ($credentialsChanged) {
            $settings->forceFill(['last_test_ok' => null, 'last_tested_at' => null, 'last_test_message' => null]);
        }
        unset($data['api_key'], $data['clear_api_key'], $data['bulk_enabled']);
        $settings->fill($data);
        if ($settings->enabled && ! $settings->hasCredentials()) {
            return response()->json(['message' => 'Renseignez la clé API avant d’activer Sift.ma.', 'errors' => ['api_key' => ['Clé API obligatoire pour activer l’intégration.']]], 422);
        }
        $settings->save();

        return response()->json(['message' => 'Paramètres Sift.ma enregistrés.', 'data' => $this->payload($settings->fresh())]);
    }

    /** « Tester la connexion » with the typed key (not saved) or the saved one: GET /parcels?limit=1. */
    public function test(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $data = $request->validate([
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'auth_mode' => ['sometimes', Rule::in(['bearer', 'x-api-key'])],
        ]);
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: $settings->api_key;
        $mode = $data['auth_mode'] ?? $settings->auth_mode ?? 'bearer';
        if (blank($apiKey)) {
            return response()->json(['ok' => false, 'message' => 'Renseignez la clé API Sift.ma avant de tester.'], 422);
        }
        $saved = $apiKey === $settings->api_key && $mode === ($settings->auth_mode ?? 'bearer');
        $client = new SiftClient($apiKey, $settings->base_url ?: SiftClient::BASE_URL, $mode);
        $started = microtime(true);
        try {
            $res = $client->checkCredentials();
            $ok = true;
            $message = 'Connexion réussie à Sift.ma en '.(int) round((microtime(true) - $started) * 1000).' ms : clé acceptée'
                .($res['total'] !== null ? " ({$res['total']} colis sur le compte)." : '.');
        } catch (SiftException $e) {
            $ok = false;
            $message = $client->redact($e->getMessage());
            if ($saved) {
                SiftShipmentService::for($settings)->logError('test', $e, $request->user());
            }
        }
        if ($saved) {
            $settings->forceFill(['last_tested_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => mb_substr($message, 0, 500)])->save();
        }

        return response()->json(['ok' => $ok, 'message' => $message, 'data' => $this->payload($settings->fresh())], $ok ? 200 : 422);
    }

    /** Shows the webhook secret once on request (to paste into Sift). */
    public function revealSecret(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(['secret' => $this->settings($request)->webhook_secret]);
    }

    public function regenerateSecret(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $settings->forceFill(['webhook_secret' => 'whsec_'.Str::random(40)])->save();

        return response()->json(['message' => 'Nouveau secret généré : mettez-le à jour chez Sift, l’ancien est refusé dès maintenant.', 'secret' => $settings->webhook_secret, 'data' => $this->payload($settings->fresh())]);
    }

    /** « Enregistrer le webhook chez Sift » (POST /webhooks — body uncertain, see docs). */
    public function registerWebhook(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $service = SiftShipmentService::for($settings);
        try {
            $service->assertReady();
            $url = $settings->webhookUrl();
            if (! str_starts_with($url, 'https://')) {
                throw new SiftException('Sift exige une URL HTTPS : configurez APP_URL en https (production lavfast-flow.com).');
            }
            $res = $service->client()->registerWebhook($url, SiftSetting::WEBHOOK_EVENTS, (string) $settings->webhook_secret);
        } catch (SiftException $e) {
            $service->logError('webhook_register', $e, $request->user());

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $settings->forceFill(['webhook_remote_id' => $res['id'] ?? $settings->webhook_remote_id])->save();

        return response()->json(['message' => 'Webhook enregistré chez Sift'.(($res['id'] ?? null) ? " (id {$res['id']})." : '.'), 'data' => $this->payload($settings->fresh())]);
    }

    /** « Synchroniser maintenant »: GET /parcels/{id} for every open parcel (polling fallback). */
    public function sync(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        if (! $settings->enabled || ! $settings->hasCredentials()) {
            return response()->json(['message' => 'Activez et configurez Sift.ma avant de synchroniser.'], 422);
        }
        $shipments = SiftShipment::query()->trackable()->where('company_id', $settings->company_id)->with('order')->get();
        $service = SiftShipmentService::for($settings);
        try {
            $stats = $service->syncStatus($shipments, $request->user(), 'manuel');
        } catch (SiftException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $message = "{$stats['checked']} colis vérifié(s), {$stats['updated']} statut(s) mis à jour.";
        if ($stats['errors']) {
            $message .= ' Erreurs : '.implode(' ', array_slice(array_unique($stats['errors']), 0, 3));
        }

        return response()->json(['message' => $message, 'stats' => $stats, 'data' => $this->payload($settings->fresh())], $stats['errors'] && ! $stats['checked'] ? 422 : 200);
    }

    /** Contrôle des colis: GET /parcels (pagination + filters), linked to local orders. */
    public function parcels(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customOrderNo' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $service = SiftShipmentService::for($settings);
        try {
            $service->assertReady();
            $res = $service->client()->listParcels($filters);
        } catch (SiftException $e) {
            $service->logError('list', $e, $request->user(), ['payload' => $filters]);

            return response()->json(['message' => $e->getMessage(), 'data' => [], 'pagination' => null], 422);
        }

        return response()->json(['data' => $this->linkLocal($settings, $res['items']), 'pagination' => $res['pagination']]);
    }

    /** Lookup by tracking number (GET /parcels/tracking/{tn}). */
    public function lookup(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $tn = trim((string) $request->validate(['tracking' => ['required', 'string', 'max:120']])['tracking']);
        $service = SiftShipmentService::for($settings);
        try {
            $service->assertReady();
            $res = $service->client()->findByTracking($tn);
        } catch (SiftException $e) {
            if ($e->httpStatus !== 404) {
                $service->logError('lookup', $e, $request->user(), ['payload' => ['tracking' => $tn]]);
            }

            return response()->json(['message' => $e->httpStatus === 404 ? "Aucun colis Sift avec le n° {$tn}." : $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->linkLocal($settings, [$res['parcel']])[0]]);
    }

    /** GET /products — preparation only (Shopify stays the catalog). */
    public function products(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        try {
            $res = SiftShipmentService::for($settings)->searchProducts($request->query('q'), $request->user());
        } catch (SiftException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => []], 422);
        }

        return response()->json(['data' => $res['items'], 'pagination' => $res['pagination'] ?? null]);
    }

    public function webhookEvents(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $events = SiftWebhookEvent::query()->where('company_id', $settings->company_id)->with('order')->latest('id')->limit(200)->get();

        return response()->json(['data' => $events->map->toSummary()->values()]);
    }

    public function logs(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $logs = SiftApiLog::query()->where('company_id', $settings->company_id)
            ->when($request->query('status') === 'open', fn ($q) => $q->where('resolved', false))
            ->with(['order', 'user'])->latest('id')->limit(200)->get();

        return response()->json(['data' => $logs->map->toSummary()->values()]);
    }

    public function retry(Request $request, SiftApiLog $log): JsonResponse
    {
        $settings = $this->settings($request);
        abort_unless((int) $log->company_id === (int) $settings->company_id, 404);
        if (! in_array($log->action, SiftApiLog::RETRYABLE, true) || $log->resolved) {
            return response()->json(['message' => 'Cette erreur ne peut pas être relancée.'], 422);
        }
        try {
            $message = SiftShipmentService::for($settings)->retry($log, $request->user());
        } catch (SiftException $e) {
            return response()->json(['message' => str_replace("\n", ' ', $e->getMessage()), 'data' => $log->fresh(['order', 'user'])->toSummary()], 422);
        }

        return response()->json(['message' => $message, 'data' => $log->fresh(['order', 'user'])->toSummary()]);
    }

    /** Adds the local order (if any) to each remote parcel. */
    protected function linkLocal(SiftSetting $settings, array $items): array
    {
        $parcelIds = array_filter(array_column($items, 'parcel_id'));
        $tns = array_filter(array_column($items, 'tracking_number'));
        $local = SiftShipment::query()->where('company_id', $settings->company_id)
            ->where(fn ($q) => $q->whereIn('parcel_id', $parcelIds ?: ['__none__'])->orWhereIn('tracking_number', $tns ?: ['__none__']))
            ->with('order:id,name,order_number')->get();
        $mapping = $settings->mapping();

        return array_map(function (array $p) use ($local, $mapping) {
            $s = $local->first(fn ($s) => ($p['parcel_id'] && $s->parcel_id === $p['parcel_id']) || ($p['tracking_number'] && $s->tracking_number === $p['tracking_number']));
            $mapped = SiftStatusMap::find($mapping, $p['status']);
            unset($p['history']);

            return $p + [
                'status_label' => SiftStatusMap::label($p['status']),
                'mapped_status' => $mapped ?: null,
                'order' => $s?->order ? ['id' => $s->order->id, 'reference' => $s->order->reference()] : null,
                'local_state' => $s?->hidden_at ? 'hidden' : $s?->state,
            ];
        }, $items);
    }

    public static function maskKey(?string $key): ?string
    {
        return blank($key) ? null : str_repeat('•', 8).mb_substr($key, -4);
    }

    protected function payload(SiftSetting $s): array
    {
        $counts = SiftShipment::query()->where('company_id', $s->company_id)->whereNull('hidden_at')->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $seen = array_map([SiftStatusMap::class, 'key'], (array) ($s->seen_statuses ?? []));
        $mapping = [];
        foreach ($s->mapping() as $raw => $code) {
            $mapping[] = ['raw' => (string) $raw, 'label' => SiftStatusMap::label((string) $raw), 'code' => $code, 'seen' => in_array(SiftStatusMap::key((string) $raw), $seen, true)];
        }
        $url = $s->webhookUrl();
        $secret = (string) $s->webhook_secret;

        return [
            'enabled' => (bool) $s->enabled,
            'base_url' => $s->base_url ?: SiftClient::BASE_URL,
            'default_base_url' => SiftClient::BASE_URL,
            'has_api_key' => filled($s->api_key),
            'api_key_hint' => self::maskKey($s->api_key),
            'auth_mode' => $s->auth_mode ?: 'bearer',
            'default_allow_open' => (bool) $s->default_allow_open,
            'items_mode' => $s->items_mode ?: 'manual',
            'send_note' => (bool) $s->send_note,
            'waybill_format' => $s->waybill_format ?: 'STANDARD_100x100',
            'waybill_formats' => SiftSetting::WAYBILL_FORMATS,
            'auto_sync' => (bool) $s->auto_sync,
            'bulk_enabled' => SiftShipmentService::bulkEnabled(),
            'status_mapping' => $mapping,
            'statuses' => SiftStatusMap::LABELS,
            'webhook' => [
                'url' => $url,
                'https' => str_starts_with($url, 'https://'),
                'secret_hint' => $secret !== '' ? mb_substr($secret, 0, 6).str_repeat('•', 8).mb_substr($secret, -4) : null,
                'events' => SiftSetting::WEBHOOK_EVENTS,
                'remote_id' => $s->webhook_remote_id,
                'last_received_at' => $s->webhook_last_received_at?->toIso8601String(),
            ],
            'last_tested_at' => $s->last_tested_at?->toIso8601String(),
            'last_test_ok' => $s->last_test_ok,
            'last_test_message' => $s->last_test_message,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'last_sync_message' => $s->last_sync_message,
            'state' => ! $s->enabled ? 'inactif' : (! $s->hasCredentials() ? 'incomplet' : ($s->last_test_ok === false ? 'erreur' : ($s->last_test_ok ? 'connecte' : 'non_teste'))),
            'ready' => $s->missingForShipping() === [],
            'missing' => $s->missingForShipping(),
            'options' => [
                'delivery_statuses' => DeliveryStatus::query()->orderBy('sort_order')->get(['code', 'name', 'color', 'category', 'is_active'])->values(),
            ],
            'stats' => [
                'active' => (int) ($counts[SiftShipment::STATE_CREATED] ?? 0),
                'delivered' => (int) ($counts[SiftShipment::STATE_DELIVERED] ?? 0),
                'returned' => (int) ($counts[SiftShipment::STATE_RETURNED] ?? 0),
                'cancelled' => (int) ($counts[SiftShipment::STATE_CANCELLED] ?? 0),
                'open_errors' => SiftApiLog::query()->where('company_id', $s->company_id)->where('resolved', false)->count(),
                'webhook_events' => SiftWebhookEvent::query()->where('company_id', $s->company_id)->count(),
            ],
        ];
    }
}
