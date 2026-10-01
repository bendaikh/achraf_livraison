<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('contact_wa_id');
            $table->string('contact_phone')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('status', 32)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'contact_wa_id'], 'wa_conv_account_contact_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'last_message_at']);
            $table->index(['company_id', 'assigned_to']);
        });

        Schema::create('whatsapp_conversation_order', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['whatsapp_conversation_id', 'order_id'], 'wa_conv_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_order');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
