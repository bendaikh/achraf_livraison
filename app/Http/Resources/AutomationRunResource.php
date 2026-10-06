<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'automation_name' => $this->whenLoaded('automation', fn () => $this->automation?->name),
            'automation_version' => $this->automation_version,
            'status' => $this->status,
            'simulation' => (bool) $this->simulation,
            'trigger_type' => $this->trigger_type,
            'trigger_payload' => $this->trigger_payload,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'idempotency_key' => $this->idempotency_key,
            'context' => $this->context,
            'current_step_key' => $this->current_step_key,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'resume_at' => $this->resume_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($s) => [
                'id' => $s->id,
                'step_key' => $s->step_key,
                'type' => $s->type,
                'status' => $s->status,
                'input_json' => $s->input_json,
                'output_json' => $s->output_json,
                'error' => $s->error,
                'attempt' => $s->attempt,
                'started_at' => $s->started_at?->toIso8601String(),
                'finished_at' => $s->finished_at?->toIso8601String(),
            ])),
        ];
    }
}
