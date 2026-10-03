<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** T6 — Équipe commerciale: services, user profile/remuneration, agent commissions. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->foreignId('service_id')->nullable()->after('role')->constrained('services')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('service_id')->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('manager_id');
            $table->string('commission_mode', 30)->default('none')->after('is_active');
            $table->decimal('commission_value', 10, 2)->nullable()->after('commission_mode');
            $table->string('commission_trigger', 30)->nullable()->after('commission_value');
        });

        Schema::create('agent_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period', 7)->nullable(); // YYYY-MM for the monthly fixed amount
            $table->string('mode', 30);
            $table->string('trigger', 30)->nullable();
            $table->decimal('rate', 10, 2); // snapshot of the agent's value when generated (never recalculated)
            $table->decimal('base_amount', 10, 2)->nullable(); // order total for % commissions
            $table->decimal('amount', 10, 2);
            $table->string('state', 20)->default('pending'); // pending | validated | paid | cancelled
            $table->timestamp('generated_at');
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'order_id', 'period']);
            $table->index(['state', 'generated_at']);
        });

        $now = now();
        $companyId = DB::table('companies')->orderBy('id')->value('id');
        foreach ([
            ['Confirmation', 'Confirmation des commandes avec les clients'],
            ['Commercial', 'Vente et relance commerciale'],
            ['SAV', 'Service après-vente, retours et échanges'],
            ['Livraison', 'Coordination des livraisons'],
            ['Responsable', 'Encadrement des équipes'],
        ] as $i => [$name, $desc]) {
            DB::table('services')->insert(['company_id' => $companyId, 'name' => $name, 'description' => $desc, 'is_active' => true, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['phone', 'is_active', 'commission_mode', 'commission_value', 'commission_trigger']);
        });
        Schema::dropIfExists('services');
    }
};
