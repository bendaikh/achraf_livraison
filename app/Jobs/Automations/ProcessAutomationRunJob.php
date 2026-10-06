<?php

namespace App\Jobs\Automations;

use App\Models\Automation;
use App\Services\Automations\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $automationId,
        public ?string $subjectType,
        public mixed $subjectId,
        public array $payload = [],
        public ?string $idempotencyKey = null,
        public bool $simulate = false,
    ) {
        $this->onQueue('automations');
    }

    public function handle(AutomationEngine $engine): void
    {
        $automation = Automation::query()->find($this->automationId);
        if (! $automation || ! $automation->isActive()) {
            return;
        }

        $subject = null;
        if ($this->subjectType && $this->subjectId) {
            $subject = $this->subjectType::query()->find($this->subjectId);
        }

        $engine->start($automation, $subject, $this->payload, $this->idempotencyKey, $this->simulate);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('ProcessAutomationRunJob failed', [
            'automation_id' => $this->automationId,
            'error' => $e?->getMessage(),
        ]);
    }
}
