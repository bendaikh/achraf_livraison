<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_number')->nullable();
            $table->string('display_phone_number')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('business_portfolio_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('status', 32)->default('disconnected');
            $table->boolean('is_active')->default(true);
            $table->string('connection_method', 32)->nullable();
            $table->text('meta_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->unique(['company_id', 'phone_number_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
