<?php

namespace App\Http\Controllers;

use App\Models\SiftSetting;
use App\Models\SiftShipment;
use App\Models\SiftWebhookEvent;
use App\Services\Sift\SiftClient;
use App\Services\Sift\SiftShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sift.ma webhooks: POST /sift/webhook/{token} (HTTPS URL + dedicated secret shown in
 * Intégrations → Transporteurs → Sift.ma). Events: parcel.status_changed, parcel.delivered,
 * return.status_changed. The signature scheme is not published: SiftClient::verifySignature()
 * accepts the usual HMAC-SHA256 variants; anything else is refused (401) and recorded as
 * « rejected » with the header NAMES only, so the real scheme can be read once Sift sends one.
 * Idempotent on the event id (payload id / header, fallback sha1 of the body).
 */
class SiftWebhookController extends Controller
{
    public const SIGNATURE_HEADERS = ['X-Sift-Signature', 'X-Signature', 'X-Webhook-Signature', 'X-Hub-Signature-256', 'Sift-Signature'];

    public const TIMESTAMP_HEADERS = ['X-Sift-Timestamp', 'X-Timestamp', 'X-Webhook-Timestamp'];

    public const MAX_SKEW_SECONDS = 600;

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $settings = SiftSetting::query()->where('webhook_token', $token)->first();
        if (! $settings) {
            return $this->reply(false, 'Unknown webhook', 404);
        }
        $raw = $request->getContent();
        $headerNames = array_values(array_map('strtolower', array_keys($request->headers->all())));

        $signature = null;
        foreach (self::SIGNATURE_HEADERS as $h) {
            if (filled($request->header($h))) {
                $signature = (string) $request->header($h);
                break;
            }
        }
        $timestamp = null;
        foreach (self::TIMESTAMP_HEADERS as $h) {
            if (filled($request->header($h))) {
                $timestamp = (string) $request->header($h);
                break;
            }
        }
        $variant = SiftClient::verifySignature((string) $settings->webhook_secret, $raw, $signature, $timestamp);
        if (! $variant) {
            return $this->reject($settings, $headerNames, $signature ? 'Signature invalide' : 'Signature absente', $raw);
        }
        if (in_array($variant, ['stripe', 'timestamp.body', 'timestamp\nbody'], true)) {
            $ts = $variant === 'stripe' && preg_match('/t=(\d+)/', (string) $signature, $m) ? (int) $m[1] : (int) $timestamp;
            $ts = $ts > 9999999999 ? intdiv($ts, 1000) : $ts;
            if (abs(time() - $ts) > self::MAX_SKEW_SECONDS) {
                return $this->reject($settings, $headerNames, 'Horodatage expiré', $raw);
            }
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return $this->reply(false, 'Invalid JSON', 400);
        }
        $type = (string) ($payload['event'] ?? $payload['type'] ?? $payload['eventType'] ?? $request->header('X-Sift-Event') ?? '');
        $eventId = (string) ($payload['eventId'] ?? $payload['event_id'] ?? $payload['id'] ?? $request->header('X-Sift-Event-Id') ?? $request->header('X-Webhook-Id') ?? '');
        if ($eventId === '' || $eventId === (string) data_get($payload, 'data.id')) {
            $eventId = 'sha1_'.sha1($raw);
        }

        $existing = SiftWebhookEvent::query()->where('event_id', $eventId)->first();
        if ($existing?->processed_at) {
            return $this->reply(true, null); // duplicate delivery (retry)
        }
        try {
            $event = $existing ?? SiftWebhookEvent::create([
                'company_id' => $settings->company_id, 'event_id' => mb_substr($eventId, 0, 191), 'event_type' => $type !== '' ? mb_substr($type, 0, 60) : null,
                'payload' => $payload, 'headers' => ['names' => $headerNames, 'signature_header' => $this->usedHeader($request), 'variant' => $variant],
            ]);
        } catch (QueryException) {
            return $this->reply(true, null); // concurrent duplicate
        }
        $settings->forceFill(['webhook_last_received_at' => now()])->save();

        try {
            $result = $this->handle($settings, $type, $payload);
        } catch (Throwable $e) {
            Log::error('Sift webhook failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
            $event->forceFill(['status' => 'failed', 'message' => Str::limit($e->getMessage(), 490, '')])->save();

            return $this->reply(false, 'Processing error', 500); // Sift may retry
        }
        $event->forceFill($result + ['processed_at' => now()])->save();

        return $this->reply(true, null);
    }

    /** Finds the shipment (parcelId → tracking → customOrderNo) and applies the status. */
    protected function handle(SiftSetting $settings, string $type, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $parcelData = is_array($data['parcel'] ?? null) ? $data['parcel'] : (is_array($data['object'] ?? null) ? $data['object'] : $data);
        $p = SiftClient::normaliseParcel($parcelData);
        $return = is_array($data['return'] ?? null) ? $data['return'] : null;
        if ($parcelData === $payload && ! isset($payload['parcelId']) && ! isset($payload['parcel_id'])) {
            $p['parcel_id'] = null; // flat payload: its "id" is the event id, not the parcel
        }
        if ($type === 'return.status_changed') {
            $p['parcel_id'] = $this->str($return['parcelId'] ?? $data['parcelId'] ?? null) ?? $p['parcel_id'];
            $p['status'] = $this->str($return['status'] ?? $data['returnStatus'] ?? null) ?? $p['status'];
        }
        if ($type === 'parcel.delivered' && ! $p['status']) {
            $p['status'] = 'delivered';
        }
        $p['status'] ??= $this->str($data['newStatus'] ?? $data['new_status'] ?? $data['to'] ?? null);

        $q = SiftShipment::query()->where(fn ($w) => $w->where('company_id', $settings->company_id)->orWhereNull('company_id'))->with('order');
        $shipment = null;
        if ($p['parcel_id']) {
            $shipment = (clone $q)->where('parcel_id', $p['parcel_id'])->latest('id')->first();
        }
        if (! $shipment && $p['tracking_number']) {
            $shipment = (clone $q)->where('tracking_number', $p['tracking_number'])->latest('id')->first();
        }
        if (! $shipment && $p['custom_order_no']) {
            $shipment = (clone $q)->where('custom_order_no', $p['custom_order_no'])->latest('id')->first();
        }
        $base = ['tracking_number' => $p['tracking_number'] ? mb_substr($p['tracking_number'], 0, 120) : null, 'parcel_id' => $p['parcel_id'] ? mb_substr($p['parcel_id'], 0, 120) : null];
        if (! $shipment) {
            return $base + ['status' => 'ignored', 'message' => 'Colis inconnu de Lav’Fast Flow.'];
        }
        if (! $p['status']) {
            return $base + ['status' => 'ignored', 'sift_shipment_id' => $shipment->id, 'order_id' => $shipment->order_id, 'message' => 'Aucun statut dans l’événement.'];
        }
        $label = match ($type) {
            'parcel.delivered' => 'Webhook Sift (livré)',
            'return.status_changed' => 'Webhook Sift (retour)',
            default => 'Webhook Sift',
        };
        $changed = SiftShipmentService::for($settings)->applyParcel($shipment, $p, 'webhook', null, $label);

        return $base + ['status' => 'processed', 'sift_shipment_id' => $shipment->id, 'order_id' => $shipment->order_id,
            'message' => 'Statut '.$p['status'].($changed ? ' appliqué à la commande.' : ' enregistré.')];
    }

    protected function reject(SiftSetting $settings, array $headerNames, string $reason, string $raw): JsonResponse
    {
        Log::warning('Sift webhook rejected', ['company_id' => $settings->company_id, 'reason' => $reason, 'headers' => $headerNames]);
        $body = json_decode($raw, true);
        SiftWebhookEvent::create([
            'company_id' => $settings->company_id, 'event_id' => 'rejected_'.Str::uuid(), 'status' => 'rejected',
            'event_type' => is_array($body) ? mb_substr((string) ($body['event'] ?? $body['type'] ?? ''), 0, 60) ?: null : null,
            'headers' => ['names' => $headerNames], 'message' => $reason.' (en-têtes reçus enregistrés, valeurs non conservées).',
        ]);

        return $this->reply(false, $reason, 401);
    }

    protected function usedHeader(Request $request): ?string
    {
        foreach (self::SIGNATURE_HEADERS as $h) {
            if (filled($request->header($h))) {
                return $h;
            }
        }

        return null;
    }

    protected function str(mixed $v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    protected function reply(bool $ok, ?string $error, int $status = 200): JsonResponse
    {
        return response()->json(['success' => $ok, 'error' => $error], $status);
    }
}
