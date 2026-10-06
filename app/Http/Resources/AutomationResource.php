<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'status_label' => \App\Models\Automation::STATUSES[$this->status] ?? $this->status,
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config,
            'definition' => $this->definition,
            'version' => $this->version,
            'integrations' => $this->usedIntegrations(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'runs_count' => $this->runs_count,
            'success_count' => $this->success_count,
            'error_count' => $this->error_count,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
