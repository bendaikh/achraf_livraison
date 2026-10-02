<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryStatus;
use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Services\Speedaf\SpeedafClient;
use App\Services\Speedaf\SpeedafException;
use App\Services\Speedaf\SpeedafShipmentService;
use App\Services\Speedaf\SpeedafStatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Intégrations → Speedaf (settings of the signed-in user's company). The secret key is never
 * returned to the browser: only a masked hint.
 */
class SpeedafIntegrationController extends Controller
{
    public const OPTIONS = [
        'parcel_types' => ['PT01' => 'Express', 'PT02' => 'Groupage (LTL)'],
        'delivery_types' => ['DE01' => 'Livraison à domicile', 'DE02' => 'Retrait en agence'],
        'transport_types' => ['TT01' => 'Route', 'TT02' => 'Aérien', 'TT03' => 'Maritime', 'TT04' => 'Aérien + maritime'],
        'ship_types' => ['ST01' => 'Expédition standard (ST01)'],
        'pay_methods' => ['PA01' => 'Espèces', 'PA02' => 'Facturation mensuelle'],
        'goods_types' => [
            'IT01' => 'Marchandise générale', 'IT02' => 'Documents', 'IT03' => 'Objets de valeur', 'IT04' => 'Avec batterie',
            'IT05' => 'Sans batterie', 'IT06' => 'Batterie seule', 'IT07' => 'Marchandise sensible',
        ],
        'pickup_agings' => [0 => 'Aucun (par défaut)', 1 => 'Ramassage programmé', 2 => 'Dépôt par le client'],
        'label_types' => [2 => 'Double feuillet 10×18', 5 => 'Double feuillet avec logo 10×15', 46 => 'Étiquette Maroc 10×10'],
        'countries' => ['MA' => 'Maroc', 'NG' => 'Nigeria', 'GH' => 'Ghana', 'EG' => 'Égypte', 'KE' => 'Kenya'],
    ];

    protected function settings(Request $request): SpeedafSetting
    {
        return SpeedafSetting::forCompany($request->user()->resolveCompanyId());
    }

    protected function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Seuls les administrateurs peuvent configurer Speedaf.');
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->settings($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $data = $request->validate($this->rules(), [], $this->attributeNames());

        $secret = trim((string) ($data['secret_key'] ?? ''));
        unset($data['secret_key']);
        if ($request->boolean('clear_secret_key')) {
            $settings->secret_key = null;
        } elseif ($secret !== '') {
            $settings->secret_key = $secret;
        }
        unset($data['clear_secret_key']);

        if (array_key_exists('status_mapping', $data)) {
            $valid = DeliveryStatus::query()->pluck('code')->all();
            $mapping = [];
            foreach (array_keys(SpeedafStatusMap::EVENTS) as $code) {
                $value = $data['status_mapping'][(string) $code] ?? null;
                $mapping[(string) $code] = $value && in_array($value, $valid, true) ? $value : null;
            }
            $data['status_mapping'] = $mapping;
        }
        foreach (['app_code', 'customer_code', 'platform_source', 'sender_name', 'sender_mobile', 'sender_address', 'sender_province', 'sender_city', 'sender_district'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = is_string($data[$field]) ? (trim($data[$field]) ?: null) : $data[$field];
            }
        }

        // Switching account/environment invalidates the last test.
        if (($data['app_code'] ?? $settings->app_code) !== $settings->app_code || ($data['environment'] ?? $settings->environment) !== $settings->environment) {
            $data['last_test_ok'] = null;
            $data['last_tested_at'] = null;
            $data['last_test_message'] = null;
        }

        $settings->fill($data);
        if ($settings->enabled && ! $settings->hasCredentials()) {
            return response()->json(['message' => 'Renseignez l’App Code et le Code client avant d’activer Speedaf.', 'errors' => ['app_code' => ['App Code et Code client obligatoires pour activer l’intégration.']]], 422);
        }
        $settings->save();

        return response()->json(['message' => 'Paramètres Speedaf enregistrés.', 'data' => $this->payload($settings->fresh())]);
    }

    /** "Tester la connexion": a read-only call (region list) authenticated with the appCode. */
    public function test(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        $data = $request->validate([
            'environment' => ['sometimes', Rule::in(array_keys(SpeedafSetting::ENVIRONMENTS))],
            'app_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        // Test the values typed in the form without saving them.
        $probe = $settings->replicate();
        foreach (['environment', 'app_code', 'country_code'] as $field) {
            if (filled($data[$field] ?? null)) {
                $probe->{$field} = trim($data[$field]);
            }
        }
        if (blank($probe->app_code)) {
            return response()->json(['ok' => false, 'message' => 'Renseignez l’App Code fourni par Speedaf avant de tester.'], 422);
        }

        $started = microtime(true);
        try {
            $regions = SpeedafClient::for($probe)->areas(strtoupper($probe->country_code ?: 'MA'), 1);
            $ms = (int) round((microtime(true) - $started) * 1000);
            $env = SpeedafSetting::ENVIRONMENTS[$probe->environment] ?? $probe->environment;
            $ok = true;
            $message = "Connexion réussie à Speedaf ({$env}) en {$ms} ms — ".count($regions).' région(s) disponibles.';
        } catch (SpeedafException $e) {
            $ok = false;
            $message = $e->getMessage();
        }

        // Only remember the result when the tested values are the saved ones.
        if ($probe->app_code === $settings->app_code && $probe->environment === $settings->environment) {
            $settings->forceFill(['last_tested_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => mb_substr($message, 0, 500)])->save();
        }

        return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
    }

    /** Registers our webhook URL at Speedaf (§5.4). */
    public function subscribeWebhook(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $settings = $this->settings($request);
        if (! $settings->hasCredentials()) {
            return response()->json(['message' => 'Enregistrez d’abord l’App Code et le Code client.'], 422);
        }
        $url = $settings->webhookUrl();
        if (! str_starts_with($url, 'https://') && $settings->environment === 'production') {
            return response()->json(['message' => "L’URL du webhook doit être publique en HTTPS ({$url})."], 422);
        }

        try {
            SpeedafClient::for($settings)->subscribeWebhook($url);
        } catch (SpeedafException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $settings->forceFill(['webhook_subscribed_at' => now()])->save();

        return response()->json(['message' => 'Webhook Speedaf enregistré : les statuts seront mis à jour automatiquement.', 'data' => $this->payload($settings->fresh())]);
    }

    /** "Synchroniser maintenant" (same as the scheduled speedaf:sync). */
    public function sync(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        if (! $settings->enabled || ! $settings->hasCredentials()) {
            return response()->json(['message' => 'Activez et configurez Speedaf avant de synchroniser.'], 422);
        }
        $shipments = SpeedafShipment::query()->trackable()->where('company_id', $settings->company_id)->with('order')->get();
        $stats = SpeedafShipmentService::for($settings)->sync($shipments);

        $message = "{$stats['checked']} envoi(s) vérifié(s), {$stats['updated']} statut(s) mis à jour.";
        if ($stats['errors']) {
            $message .= ' Erreurs : '.implode(' ', array_unique($stats['errors']));
        }

        return response()->json(['message' => $message, 'stats' => $stats, 'data' => $this->payload($settings->fresh())], $stats['errors'] && ! $stats['checked'] ? 422 : 200);
    }

    protected function rules(): array
    {
        $in = fn (string $key) => Rule::in(array_map('strval', array_keys(self::OPTIONS[$key])));

        return [
            'enabled' => ['sometimes', 'boolean'],
            'environment' => ['sometimes', Rule::in(array_keys(SpeedafSetting::ENVIRONMENTS))],
            'app_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_secret_key' => ['sometimes', 'boolean'],
            'platform_source' => ['sometimes', 'nullable', 'string', 'max:50'],
            'parcel_type' => ['sometimes', $in('parcel_types')],
            'delivery_type' => ['sometimes', $in('delivery_types')],
            'transport_type' => ['sometimes', $in('transport_types')],
            'ship_type' => ['sometimes', $in('ship_types')],
            'pay_method' => ['sometimes', $in('pay_methods')],
            'goods_type' => ['sometimes', $in('goods_types')],
            'pickup_aging' => ['sometimes', 'integer', $in('pickup_agings')],
            'allow_open' => ['sometimes', 'boolean'],
            'default_weight' => ['sometimes', 'numeric', 'min:0.001', 'max:9999'],
            'country_code' => ['sometimes', $in('countries')],
            'currency' => ['sometimes', 'string', 'size:3'],
            'label_type' => ['sometimes', 'integer', $in('label_types')],
            'label_with_logo' => ['sometimes', 'boolean'],
            'sender_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sender_mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'sender_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sender_province' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sender_city' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sender_district' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status_mapping' => ['sometimes', 'array'],
            'status_mapping.*' => ['nullable', 'string', 'max:60'],
            'auto_sync' => ['sometimes', 'boolean'],
        ];
    }

    protected function attributeNames(): array
    {
        return [
            'app_code' => 'App Code', 'customer_code' => 'code client', 'secret_key' => 'clé secrète', 'platform_source' => 'platform source',
            'default_weight' => 'poids par défaut', 'sender_name' => 'nom expéditeur', 'sender_mobile' => 'téléphone expéditeur',
            'sender_address' => 'adresse expéditeur', 'sender_city' => 'ville expéditeur', 'environment' => 'environnement',
        ];
    }

    public static function maskSecret(?string $secret): ?string
    {
        if (blank($secret)) {
            return null;
        }

        return str_repeat('•', 8).mb_substr($secret, -4);
    }

    protected function payload(SpeedafSetting $s): array
    {
        $counts = SpeedafShipment::query()->where('company_id', $s->company_id)
            ->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');

        return [
            'enabled' => (bool) $s->enabled,
            'environment' => $s->environment,
            'base_url' => $s->baseUrl(),
            'app_code' => $s->app_code,
            'customer_code' => $s->customer_code,
            'has_secret_key' => filled($s->secret_key),
            'secret_key_hint' => self::maskSecret($s->secret_key),
            'platform_source' => $s->platform_source,
            'parcel_type' => $s->parcel_type,
            'delivery_type' => $s->delivery_type,
            'transport_type' => $s->transport_type,
            'ship_type' => $s->ship_type,
            'pay_method' => $s->pay_method,
            'goods_type' => $s->goods_type,
            'pickup_aging' => (int) $s->pickup_aging,
            'allow_open' => (bool) $s->allow_open,
            'default_weight' => (float) $s->default_weight,
            'country_code' => $s->country_code,
            'currency' => $s->currency,
            'label_type' => (int) $s->label_type,
            'label_with_logo' => (bool) $s->label_with_logo,
            'sender_name' => $s->sender_name,
            'sender_mobile' => $s->sender_mobile,
            'sender_address' => $s->sender_address,
            'sender_province' => $s->sender_province,
            'sender_city' => $s->sender_city,
            'sender_district' => $s->sender_district,
            'status_mapping' => (object) $s->mapping(),
            'auto_sync' => (bool) $s->auto_sync,
            'webhook_url' => $s->webhookUrl(),
            'webhook_subscribed_at' => $s->webhook_subscribed_at?->toIso8601String(),
            'last_tested_at' => $s->last_tested_at?->toIso8601String(),
            'last_test_ok' => $s->last_test_ok,
            'last_test_message' => $s->last_test_message,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'missing' => $s->missingForShipping(),
            'ready' => $s->missingForShipping() === [],
            'stats' => [
                'active' => (int) ($counts[SpeedafShipment::STATE_CREATED] ?? 0),
                'delivered' => (int) ($counts[SpeedafShipment::STATE_DELIVERED] ?? 0),
                'returned' => (int) ($counts[SpeedafShipment::STATE_RETURNED] ?? 0),
                'cancelled' => (int) ($counts[SpeedafShipment::STATE_CANCELLED] ?? 0),
            ],
            'options' => array_merge(
                array_map(fn ($list) => collect($list)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values(), self::OPTIONS),
                [
                    'environments' => collect(SpeedafSetting::ENVIRONMENTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'url' => SpeedafSetting::BASE_URLS[$value]])->values(),
                    'speedaf_statuses' => SpeedafStatusMap::options(),
                    'uat_sample_app_code' => SpeedafSetting::UAT_SAMPLE_APP_CODE,
                ]
            ),
        ];
    }
}
