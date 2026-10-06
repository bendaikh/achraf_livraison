<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations engine — multi-company scenario builder (trigger → conditions → wait → branches → actions).
 * Safe additive migration: creates new tables only (no drops / no destructive alters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 32)->default('draft'); // draft|active|paused|error
            $table->string('trigger_type', 64);
            $table->json('trigger_config')->nullable();
            $table->json('definition'); // graph: entry + steps
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'trigger_type']);
            $table->index(['company_id', 'archived_at']);
        });

        Schema::create('automation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->json('trigger_config')->nullable();
            $table->string('trigger_type', 64)->nullable();
            $table->string('name')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_note')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'version']);
            $table->index(['company_id', 'automation_id']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->unsignedInteger('automation_version')->nullable();
            $table->string('status', 32)->default('pending'); // pending|running|waiting|success|failed|cancelled|skipped
            $table->boolean('simulation')->default(false);
            $table->string('trigger_type', 64)->nullable();
            $table->json('trigger_payload')->nullable();
            $table->nullableMorphs('subject'); // order, client…
            $table->string('idempotency_key', 191)->nullable();
            $table->json('context')->nullable(); // accumulated step outputs
            $table->string('current_step_key', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('resume_at')->nullable(); // scheduled wait resume
            $table->timestamps();

            $table->unique(['company_id', 'automation_id', 'idempotency_key'], 'automation_runs_idempotency_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'resume_at']);
        });

        Schema::create('automation_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->string('step_key', 64);
            $table->string('type', 32); // condition|wait|action|branch
            $table->string('status', 32)->default('pending'); // pending|running|success|failed|skipped|waiting
            $table->json('input_json')->nullable();
            $table->json('output_json')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'step_key']);
            $table->index(['company_id', 'status']);
        });

        // Pending waits / retries tracked alongside Laravel queue jobs.
        Schema::create('automation_queue_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->string('type', 32); // wait|retry
            $table->string('step_key', 64)->nullable();
            $table->string('status', 32)->default('pending'); // pending|processing|done|cancelled|failed
            $table->timestamp('available_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('automation_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // null = global library
            $table->string('slug', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('trigger_type', 64);
            $table->json('trigger_config')->nullable();
            $table->json('definition');
            $table->json('integrations')->nullable(); // ['whatsapp','shopify',…]
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_templates');
        Schema::dropIfExists('automation_queue_jobs');
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_versions');
        Schema::dropIfExists('automations');
    }
};
