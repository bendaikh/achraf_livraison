<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Campaigns module — company-scoped campaigns, recipient snapshot, exclusions.
 * Additive only. Separate from Automations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            /** draft|scheduled|running|completed|paused|error|archived */
            $table->string('status', 20)->default('draft');
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->json('audience_definition')->nullable();
            $table->json('variable_mapping')->nullable();
            $table->json('manual_phone_keys')->nullable();
            $table->json('exclusion_phone_keys')->nullable();
            $table->json('stats')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'scheduled_at']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('whatsapp_campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->string('phone_key', 32);
            $table->string('customer_name')->nullable();
            $table->string('phone', 40)->nullable();
            /** pending|skipped|queued|sent|delivered|read|failed|excluded */
            $table->string('status', 20)->default('pending');
            $table->string('exclude_reason', 120)->nullable();
            $table->json('resolved_variables')->nullable();
            $table->text('preview_body')->nullable();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->string('wa_message_id')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('retriable')->default(false);
            $table->timestamps();
            $table->unique(['whatsapp_campaign_id', 'phone_key'], 'wa_campaign_recipient_unique');
            $table->index(['company_id', 'whatsapp_campaign_id', 'status'], 'wa_campaign_recipient_status_idx');
            $table->index(['company_id', 'phone_key']);
        });

        Schema::create('whatsapp_campaign_exclusion_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('whatsapp_campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->string('reason', 80);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();
            $table->unique(['whatsapp_campaign_id', 'reason'], 'wa_campaign_excl_reason_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaign_exclusion_reasons');
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
