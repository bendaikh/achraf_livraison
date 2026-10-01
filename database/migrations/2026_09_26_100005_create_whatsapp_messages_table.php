<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('whatsapp_conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('type', 32)->default('text');
            $table->text('body')->nullable();
            $table->string('media_id')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('media_filename')->nullable();
            $table->string('media_path')->nullable();
            $table->string('template_name')->nullable();
            $table->string('template_language', 16)->nullable();
            $table->json('template_components')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_name')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('meta_timestamp')->nullable();
            $table->timestamp('status_timestamp')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique('wa_message_id');
            $table->index(['whatsapp_conversation_id', 'id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
