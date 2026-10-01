<?php

namespace App\Jobs;

use App\Services\WhatsApp\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload,
    ) {}

    public function handle(WebhookProcessor $processor): void
    {
        try {
            $processor->process($this->payload);
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook processing failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
