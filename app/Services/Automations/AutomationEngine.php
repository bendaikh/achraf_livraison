<?php

namespace App\Services\Automations;

use App\Jobs\Automations\ResumeAutomationWaitJob;
use App\Models\Automation;
use App\Models\AutomationQueueJob;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes an automation definition graph: condition → wait → branch → action(s).
 * Propagates step outputs into context for {{steps.*}} variable mapping.
 */
class AutomationEngine
{
    public function __construct(
        protected AutomationRegistry $registry,
        protected VariableResolver $resolver,
        protected ConditionEvaluator $conditions,
    ) {}

    /**
     * Start (or resume) a run. Idempotent when $idempotencyKey is provided.
     *
     * @param  array<string, mixed>  $triggerPayload
     */
    public function start(
        Automation $automation,
        ?Model $subject = null,
        array $triggerPayload = [],
        ?string $idempotencyKey = null,
        bool $simulate = false,
    ): AutomationRun {
        if ($idempotencyKey) {
            $existing = AutomationRun::query()
                ->where('company_id', $automation->company_id)
                ->where('automation_id', $automation->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $context = $this->resolver->subjectContext($subject, $triggerPayload);
        $context['company_id'] = $automation->company_id;
        $context['simulate'] = $simulate;

        $entry = (string) ($automation->definition['entry'] ?? '');
        if ($entry === '' && ! empty($automation->definition['steps'])) {
            $entry = (string) array_key_first($automation->definition['steps']);
        }

        try {
            $run = DB::transaction(function () use ($automation, $subject, $triggerPayload, $idempotencyKey, $simulate, $context, $entry) {
                $run = AutomationRun::create([
                    'company_id' => $automation->company_id,
                    'automation_id' => $automation->id,
                    'automation_version' => $automation->version,
                    'status' => AutomationRun::STATUS_RUNNING,
                    'simulation' => $simulate,
                    'trigger_type' => $automation->trigger_type,
                    'trigger_payload' => $triggerPayload,
                    'subject_type' => $subject ? $subject::class : null,
                    'subject_id' => $subject?->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'context' => $context,
                    'current_step_key' => $entry ?: null,
                    'started_at' => now(),
                ]);

                if (! $simulate) {
                    $automation->increment('runs_count');
                    $automation->forceFill(['last_run_at' => now()])->saveQuietly();
                }

                return $run;
            });
        } catch (Throwable $e) {
            // Unique constraint race on idempotency_key
            if ($idempotencyKey) {
                $existing = AutomationRun::query()
                    ->where('company_id', $automation->company_id)
                    ->where('automation_id', $automation->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }

        return $this->continue($run);
    }

    /** Resume a waiting / pending run from its current_step_key. */
    public function continue(AutomationRun $run): AutomationRun
    {
        $run->refresh();
        if ($run->isTerminal()) {
            return $run;
        }

        $automation = $run->automation;
        if (! $automation) {
            return $this->fail($run, 'Automatisation introuvable.');
        }

        $definition = (array) ($automation->definition ?? []);
        // Prefer the version snapshot when present on the run context (future: load from automation_versions).
        $steps = (array) ($definition['steps'] ?? []);
        $stepKey = $run->current_step_key;

        $run->update(['status' => AutomationRun::STATUS_RUNNING, 'resume_at' => null]);

        $guard = 0;
        while ($stepKey && $guard++ < 100) {
            $step = $steps[$stepKey] ?? null;
            if (! $step) {
                return $this->fail($run, "Étape inconnue [{$stepKey}].");
            }

            $type = (string) ($step['type'] ?? '');
            $result = match ($type) {
                'condition', 'branch' => $this->runCondition($run, $stepKey, $step),
                'wait' => $this->runWait($run, $stepKey, $step),
                'action' => $this->runAction($run, $stepKey, $step),
                default => ['ok' => false, 'error' => "Type d’étape inconnu [{$type}].", 'next' => null],
            };

            if (($result['pause'] ?? false) === true) {
                return $run->fresh(['steps']);
            }

            if (! ($result['ok'] ?? false)) {
                return $this->fail($run, (string) ($result['error'] ?? 'Échec de l’étape.'), $stepKey);
            }

            $stepKey = $result['next'] ?? null;
            $run->update(['current_step_key' => $stepKey, 'context' => $run->context]);
        }

        return $this->succeed($run);
    }

    /**
     * Dry-run preview without persisting side effects (still creates a simulation run for the UI).
     *
     * @return array{run: AutomationRun, preview: list<array>}
     */
    public function simulate(Automation $automation, ?Model $subject = null, array $triggerPayload = []): array
    {
        $run = $this->start($automation, $subject, $triggerPayload, idempotencyKey: null, simulate: true);
        $preview = $run->steps->map(fn (AutomationRunStep $s) => [
            'step_key' => $s->step_key,
            'type' => $s->type,
            'status' => $s->status,
            'input' => $s->input_json,
            'output' => $s->output_json,
            'error' => $s->error,
        ])->all();

        return ['run' => $run, 'preview' => $preview];
    }

    /** @param  array<string, mixed>  $step */
    protected function runCondition(AutomationRun $run, string $stepKey, array $step): array
    {
        $context = (array) $run->context;
        $eval = $this->conditions->evaluate($step, $context);
        $matched = (bool) $eval['matched'];

        $this->recordStep($run, $stepKey, 'condition', 'success', $step, [
            'matched' => $matched,
            'results' => $eval['results'],
            'branch' => $matched ? 'then' : 'else',
        ]);

        $next = $matched ? ($step['then'] ?? null) : ($step['else'] ?? null);

        return ['ok' => true, 'next' => $next];
    }

    /** @param  array<string, mixed>  $step */
    protected function runWait(AutomationRun $run, string $stepKey, array $step): array
    {
        // Already resumed from a wait — re-check conditions then continue.
        $context = (array) $run->context;
        $waitMeta = (array) ($context['__waits'][$stepKey] ?? []);

        if (! empty($waitMeta['resumed'])) {
            if (($step['recheck_conditions'] ?? true) && ! empty($step['conditions']['rules'])) {
                // Refresh subject fields before recheck
                $context = $this->refreshSubjectContext($run, $context);
                $eval = $this->conditions->evaluate((array) $step['conditions'], $context);
                $this->recordStep($run, $stepKey, 'wait', $eval['matched'] ? 'success' : 'skipped', $step, [
                    'resumed' => true,
                    'recheck' => $eval,
                ]);
                if (! $eval['matched']) {
                    $run->context = $context;
                    $this->cancel($run, 'Conditions non remplies après l’attente — exécution annulée.');

                    return ['ok' => true, 'pause' => true];
                }
            } else {
                $this->recordStep($run, $stepKey, 'wait', 'success', $step, ['resumed' => true]);
            }

            // Clear wait marker
            unset($context['__waits'][$stepKey]);
            $run->context = $context;

            return ['ok' => true, 'next' => $step['next'] ?? null];
        }

        $resumeAt = $this->computeResumeAt($step);
        $simulate = (bool) $run->simulation;

        // In simulation (or zero-duration), skip the real delay — still recheck conditions if configured.
        if ($simulate || $resumeAt->lte(now())) {
            if (($step['recheck_conditions'] ?? true) && ! empty($step['conditions']['rules'])) {
                $context = $this->refreshSubjectContext($run, $context);
                $eval = $this->conditions->evaluate((array) $step['conditions'], $context);
                $this->recordStep($run, $stepKey, 'wait', $eval['matched'] ? 'success' : 'skipped', $step, [
                    'simulated' => $simulate,
                    'resume_at' => $resumeAt->toIso8601String(),
                    'skipped_delay' => true,
                    'recheck' => $eval,
                ]);
                if (! $eval['matched']) {
                    $run->context = $context;
                    $this->cancel($run, 'Conditions non remplies après l’attente — exécution annulée.');

                    return ['ok' => true, 'pause' => true];
                }
                $run->context = $context;

                return ['ok' => true, 'next' => $step['next'] ?? null];
            }

            $this->recordStep($run, $stepKey, 'wait', 'success', $step, [
                'simulated' => $simulate,
                'resume_at' => $resumeAt->toIso8601String(),
                'skipped_delay' => true,
            ]);

            return ['ok' => true, 'next' => $step['next'] ?? null];
        }

        $context['__waits'][$stepKey] = ['scheduled_at' => now()->toIso8601String(), 'resume_at' => $resumeAt->toIso8601String()];
        $run->context = $context;
        $run->update([
            'status' => AutomationRun::STATUS_WAITING,
            'current_step_key' => $stepKey,
            'resume_at' => $resumeAt,
            'context' => $context,
        ]);

        $queueJob = AutomationQueueJob::create([
            'company_id' => $run->company_id,
            'automation_run_id' => $run->id,
            'type' => AutomationQueueJob::TYPE_WAIT,
            'step_key' => $stepKey,
            'status' => AutomationQueueJob::STATUS_PENDING,
            'available_at' => $resumeAt,
            'payload' => ['next' => $step['next'] ?? null],
        ]);

        $this->recordStep($run, $stepKey, 'wait', 'waiting', $step, [
            'resume_at' => $resumeAt->toIso8601String(),
            'queue_job_id' => $queueJob->id,
        ]);

        ResumeAutomationWaitJob::dispatch($run->id, $stepKey)->delay($resumeAt);

        return ['ok' => true, 'pause' => true];
    }

    /** @param  array<string, mixed>  $step */
    protected function runAction(AutomationRun $run, string $stepKey, array $step): array
    {
        $actionKey = (string) ($step['action'] ?? '');
        $action = $this->registry->action($actionKey);
        if (! $action) {
            return ['ok' => false, 'error' => "Action non enregistrée [{$actionKey}]."];
        }

        $context = (array) $run->context;
        $config = $this->resolver->interpolate((array) ($step['config'] ?? []), $context);

        try {
            $result = $action->handle($config, $context, (bool) $run->simulation);
        } catch (Throwable $e) {
            Log::warning('Automation action failed', [
                'run_id' => $run->id,
                'action' => $actionKey,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $ok = (bool) ($result['ok'] ?? false);
        $output = (array) ($result['output'] ?? []);
        $this->recordStep(
            $run,
            $stepKey,
            'action',
            $ok ? 'success' : 'failed',
            ['action' => $actionKey, 'config' => $config],
            $output + ['simulated' => (bool) ($result['simulated'] ?? $run->simulation)],
            $ok ? null : (string) ($result['error'] ?? 'Échec action'),
        );

        if (! $ok) {
            // Schedule retry for non-simulation failures when step allows it.
            if (! $run->simulation && ($step['retry'] ?? true)) {
                $this->scheduleRetry($run, $stepKey, (string) ($result['error'] ?? 'Échec'));
            }

            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Échec action')];
        }

        // Propagate outputs into context for subsequent steps
        $context['steps'][$stepKey] = $output;
        foreach ($output as $k => $v) {
            if (! is_int($k)) {
                $context['vars'][$k] = $v;
            }
        }
        $run->context = $context;

        return ['ok' => true, 'next' => $step['next'] ?? null];
    }

    /** Mark a wait as resumed and continue execution (called by ResumeAutomationWaitJob). */
    public function resumeWait(AutomationRun $run, string $stepKey): AutomationRun
    {
        $run->refresh();
        if ($run->isTerminal()) {
            return $run;
        }
        if ($run->status !== AutomationRun::STATUS_WAITING && $run->current_step_key !== $stepKey) {
            return $run;
        }

        $context = (array) $run->context;
        $context['__waits'][$stepKey]['resumed'] = true;
        $run->context = $context;
        $run->update(['context' => $context, 'current_step_key' => $stepKey]);

        AutomationQueueJob::query()
            ->where('automation_run_id', $run->id)
            ->where('step_key', $stepKey)
            ->where('type', AutomationQueueJob::TYPE_WAIT)
            ->where('status', AutomationQueueJob::STATUS_PENDING)
            ->update([
                'status' => AutomationQueueJob::STATUS_DONE,
                'processed_at' => now(),
            ]);

        return $this->continue($run);
    }

    public function retry(AutomationRun $run): AutomationRun
    {
        if ($run->status !== AutomationRun::STATUS_FAILED) {
            return $run;
        }

        $run->update([
            'status' => AutomationRun::STATUS_RUNNING,
            'error_message' => null,
            'finished_at' => null,
        ]);

        return $this->continue($run);
    }

    protected function succeed(AutomationRun $run): AutomationRun
    {
        $run->update([
            'status' => AutomationRun::STATUS_SUCCESS,
            'finished_at' => now(),
            'current_step_key' => null,
            'context' => $run->context,
        ]);

        if (! $run->simulation) {
            $run->automation?->increment('success_count');
        }

        return $run->fresh(['steps']);
    }

    protected function fail(AutomationRun $run, string $message, ?string $stepKey = null): AutomationRun
    {
        $run->update([
            'status' => AutomationRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
            'current_step_key' => $stepKey ?? $run->current_step_key,
            'context' => $run->context,
        ]);

        if (! $run->simulation) {
            $run->automation?->increment('error_count');
            $run->automation?->update(['status' => Automation::STATUS_ERROR]);
        }

        return $run->fresh(['steps']);
    }

    protected function cancel(AutomationRun $run, string $message): AutomationRun
    {
        $run->update([
            'status' => AutomationRun::STATUS_CANCELLED,
            'error_message' => $message,
            'finished_at' => now(),
            'context' => $run->context,
        ]);

        AutomationQueueJob::query()
            ->where('automation_run_id', $run->id)
            ->where('status', AutomationQueueJob::STATUS_PENDING)
            ->update(['status' => AutomationQueueJob::STATUS_CANCELLED, 'processed_at' => now()]);

        return $run->fresh(['steps']);
    }

    protected function recordStep(
        AutomationRun $run,
        string $stepKey,
        string $type,
        string $status,
        ?array $input,
        ?array $output,
        ?string $error = null,
    ): AutomationRunStep {
        return AutomationRunStep::create([
            'company_id' => $run->company_id,
            'automation_run_id' => $run->id,
            'step_key' => $stepKey,
            'type' => $type,
            'status' => $status,
            'input_json' => $input,
            'output_json' => $output,
            'error' => $error,
            'started_at' => now(),
            'finished_at' => $status === 'waiting' ? null : now(),
        ]);
    }

    /** @param  array<string, mixed>  $step */
    protected function computeResumeAt(array $step): Carbon
    {
        $unit = (string) ($step['unit'] ?? 'minutes');
        $amount = (int) ($step['amount'] ?? 0);
        $tz = config('app.timezone', 'Africa/Casablanca');

        if ($unit === 'until' && ! empty($step['until'])) {
            return Carbon::parse($step['until'], $tz);
        }

        return match ($unit) {
            'hours' => now($tz)->addHours(max(0, $amount)),
            'days' => now($tz)->addDays(max(0, $amount)),
            default => now($tz)->addMinutes(max(0, $amount)),
        };
    }

    /** @param  array<string, mixed>  $context */
    protected function refreshSubjectContext(AutomationRun $run, array $context): array
    {
        if (! $run->subject_type || ! $run->subject_id) {
            return $context;
        }
        $subject = $run->subject_type::query()->find($run->subject_id);
        if (! $subject) {
            return $context;
        }
        $fresh = $this->resolver->subjectContext($subject, (array) $run->trigger_payload);
        $context['order'] = $fresh['order'] ?? ($context['order'] ?? null);
        $context['subject'] = $fresh['subject'] ?? ($context['subject'] ?? null);

        return $context;
    }

    protected function scheduleRetry(AutomationRun $run, string $stepKey, string $error): void
    {
        $attempts = AutomationQueueJob::query()
            ->where('automation_run_id', $run->id)
            ->where('step_key', $stepKey)
            ->where('type', AutomationQueueJob::TYPE_RETRY)
            ->count();

        $max = 5;
        if ($attempts >= $max) {
            return;
        }

        $delayMinutes = [1, 5, 15, 60, 180][$attempts] ?? 180;
        AutomationQueueJob::create([
            'company_id' => $run->company_id,
            'automation_run_id' => $run->id,
            'type' => AutomationQueueJob::TYPE_RETRY,
            'step_key' => $stepKey,
            'status' => AutomationQueueJob::STATUS_PENDING,
            'available_at' => now()->addMinutes($delayMinutes),
            'attempts' => $attempts + 1,
            'max_attempts' => $max,
            'last_error' => $error,
        ]);
    }
}
