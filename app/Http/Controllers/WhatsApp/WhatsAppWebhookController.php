<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Services\WhatsApp\MetaOAuth;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, MetaOAuth $meta): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $result = $meta->verifyWebhookChallenge($mode, $token, $challenge);
        if ($result === null) {
            return response('Forbidden', 403);
        }

        return response($result, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, MetaOAuth $meta): Response
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        // Allow verify without secret during initial setup only if no secret configured
        if (filled($meta->appSecret()) && ! $meta->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('WhatsApp webhook signature verification failed');

            return response('Invalid signature', 401);
        }

        $payload = json_decode($rawBody, true) ?? [];

        ProcessWhatsAppWebhookJob::dispatchSync($payload);

        return response('EVENT_RECEIVED', 200);
    }
}
