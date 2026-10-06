<?php

namespace App\Services\Automations\Contracts;

/**
 * Contract for a registered automation action.
 * Integrations register implementations via AutomationRegistry::registerAction().
 */
interface AutomationAction
{
    public function key(): string;

    public function label(): string;

    public function integration(): string;

    /** Config schema for the builder UI (list of fields). */
    public function configSchema(): array;

    /**
     * @param  array<string, mixed>  $config  Resolved action config (variables already interpolated)
     * @param  array<string, mixed>  $context  Run context (subject, previous outputs…)
     * @param  bool  $simulate  When true, no side effects
     * @return array{ok: bool, output?: array, error?: string, simulated?: bool}
     */
    public function handle(array $config, array $context, bool $simulate = false): array;
}
