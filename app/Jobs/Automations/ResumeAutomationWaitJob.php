<?php

namespace App\Jobs\Automations;

use App\Models\AutomationRun;
use App\Services\Automations\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResumeAutomationWaitJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $runId,
        public string $stepKey,
    ) {
        $this->onQueue('automations');
    }

    public function handle(AutomationEngine $engine): void
    {
        $run = AutomationRun::query()->find($this->runId);
        if (! $run || $run->isTerminal()) {
            return;
        }

        $engine->resumeWait($run, $this->stepKey);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('ResumeAutomationWaitJob failed', [
            'run_id' => $this->runId,
            'step' => $this->stepKey,
            'error' => $e?->getMessage(),
        ]);
    }
}
