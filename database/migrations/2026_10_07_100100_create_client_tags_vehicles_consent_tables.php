<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client tags, vehicles and WhatsApp marketing consent — company-scoped, phone_key identity.
 * Additive only. Used by WhatsApp Campaigns (and later Automations tag actions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('color', 20)->default('#2563eb');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('client_tag_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('client_tag_id')->constrained('client_tags')->cascadeOnDelete();
            $table->string('phone_key', 32);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_tag_id', 'phone_key']);
            $table->index(['company_id', 'phone_key']);
            $table->index(['company_id', 'client_tag_id']);
        });

        Schema::create('client_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('phone_key', 32);
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('generation', 80)->nullable();
            $table->string('phase', 80)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('body_type', 80)->nullable();
            $table->string('plate', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'phone_key']);
            $table->index(['company_id', 'brand', 'model']);
            $table->index(['company_id', 'year']);
        });

        Schema::create('client_whatsapp_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('phone_key', 32);
            /** allowed | refused | unknown */
            $table->string('status', 20)->default('unknown');
            $table->string('source', 80)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'phone_key']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('client_audience_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('description')->nullable();
            $table->json('definition');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_audience_segments');
        Schema::dropIfExists('client_whatsapp_consents');
        Schema::dropIfExists('client_vehicles');
        Schema::dropIfExists('client_tag_assignments');
        Schema::dropIfExists('client_tags');
    }
};
