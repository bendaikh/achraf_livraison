<?php

namespace App\Http\Controllers;

use App\Models\SpeedafSetting;
use App\Models\SpeedafShipment;
use App\Models\SpeedafWebhookEvent;
use App\Services\Speedaf\SpeedafClient;
use App\Services\Speedaf\SpeedafShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Speedaf tracking webhook (PDF §5.5): POST /speedaf/webhook/{token}.
 * Headers X-Speedaf-Timestamp / X-Speedaf-Signature = "hmac-sha256=" + HMAC-SHA256(secretKey,
 * timestamp + "\n" + rawBody). Idempotent on eventId. Speedaf retries unless we answer
 * HTTP 2xx with {"success": true}.
 */
class SpeedafWebhookController extends Controller
{
    public const MAX_SKEW_MS = 300000; // 5 minutes

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $settings = SpeedafSetting::query()->where('webhook_token', $token)->first();
        if (! $settings) {
            return $this->fail('Unknown webhook', 404);
        }

        $raw = $request->getContent();
        $timestamp = $request->header('X-Speedaf-Timestamp');
        $signature = $request->header('X-Speedaf-Signature');

        if (filled($settings->secret_key)) {
            if (! SpeedafClient::verifyWebhookSignature((string) $settings->secret_key, $timestamp, $raw, $signature)) {
                Log::warning('Speedaf webhook: invalid signature', ['company_id' => $settings->company_id]);

                return $this->fail('Invalid signature', 401);
            }
            if (! is_numeric($timestamp) || abs((int) floor(microtime(true) * 1000) - (int) $timestamp) > self::MAX_SKEW_MS) {
                return $this->fail('Expired timestamp', 401);
            }
        } elseif ($request->header('X-Speedaf-App-Code') && $settings->app_code && $request->header('X-Speedaf-App-Code') !== $settings->app_code) {
            return $this->fail('Unknown app code', 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return $this->fail('Invalid JSON', 400);
        }

        $eventId = (string) ($payload['eventId'] ?? $request->header('X-Speedaf-Event-Id') ?? '');
        $mailNo = (string) ($payload['mailNo'] ?? data_get($payload, 'tracks.0.mailNo', ''));
        if ($eventId === '') {
            $eventId = 'nohdr_'.sha1($raw);
        }

        $existing = SpeedafWebhookEvent::query()->where('event_id', $eventId)->first();
        if ($existing?->processed_at) {
            return $this->ok(); // duplicate push (retry)
        }
        try {
            $event = $existing ?? SpeedafWebhookEvent::create([
                'company_id' => $settings->company_id, 'event_id' => mb_substr($eventId, 0, 191), 'mail_no' => $mailNo ?: null, 'payload' => $payload,
            ]);
        } catch (QueryException) {
            return $this->ok(); // concurrent duplicate
        }

        if ($mailNo !== '') {
            $shipment = SpeedafShipment::query()->where('bill_code', $mailNo)
                ->where(fn ($q) => $q->where('company_id', $settings->company_id)->orWhereNull('company_id'))
                ->latest('id')->with('order')->first();
            if ($shipment) {
                SpeedafShipmentService::for($settings)->applyTracks($shipment, (array) ($payload['tracks'] ?? []), 'webhook');
            }
        }

        $event->forceFill(['processed_at' => now()])->save();

        return $this->ok();
    }

    protected function ok(): JsonResponse
    {
        return response()->json(['success' => true, 'error' => null, 'data' => null]);
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $message, 'data' => null], $status);
    }
}
