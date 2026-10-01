<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lavfast tasks 1-3 foundation, built ON TOP of the existing schema
 * (orders from Shopify, drivers linked to users, confirmation_statuses).
 *
 *  - orders.delivery_status keeps holding a status *code*; the codes now live in the
 *    configurable delivery_statuses table instead of being hard-coded in Order.
 *  - orders can also be created manually (no Shopify shop).
 */
return new class extends Migration
{
    /** Default statuses — the codes already used by the existing delivery workflow are kept. */
    public const DEFAULT_STATUSES = [
        ['code' => 'to_assign', 'name' => 'À attribuer', 'category' => 'avant_livraison', 'color' => '#64748b', 'icon' => 'inbox'],
        ['code' => 'assigned', 'name' => 'Attribuée', 'category' => 'avant_livraison', 'color' => '#2563eb', 'icon' => 'user-check'],
        ['code' => 'in_progress', 'name' => 'En cours', 'category' => 'en_livraison', 'color' => '#0891b2', 'icon' => 'truck'],
        ['code' => 'delivered', 'name' => 'Livrée', 'category' => 'succes', 'color' => '#059669', 'icon' => 'check-circle', 'required_fields' => ['collected_amount']],
        ['code' => 'no_answer', 'name' => 'Pas de réponse', 'category' => 'injoignable', 'color' => '#64748b', 'icon' => 'phone-off', 'required_fields' => ['reason']],
        ['code' => 'postponed', 'name' => 'Reportée', 'category' => 'report', 'color' => '#d97706', 'icon' => 'calendar-clock', 'required_fields' => ['postponed_at']],
        ['code' => 'failed', 'name' => 'Échouée', 'category' => 'echec', 'color' => '#e11d48', 'icon' => 'x-circle', 'required_fields' => ['reason']],
        ['code' => 'cancelled', 'name' => 'Annulée', 'category' => 'annulation', 'color' => '#be123c', 'icon' => 'ban', 'required_fields' => ['reason']],
        ['code' => 'returned', 'name' => 'Retour', 'category' => 'retour', 'color' => '#ea580c', 'icon' => 'undo', 'creates_mission_type' => 'retour'],
        ['code' => 'exchanged', 'name' => 'Échange', 'category' => 'retour', 'color' => '#0d9488', 'icon' => 'repeat', 'creates_mission_type' => 'echange'],
    ];

    public function up(): void
    {
        // Company-level settings (key / JSON value).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Per-user UI preferences (e.g. Commandes column selector).
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        // Delivery statuses — fully configurable (no hard-coded list).
        Schema::create('delivery_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 60)->unique();
            $table->string('color', 20)->default('#64748b');
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('category', 40)->index();
            $table->json('required_fields')->nullable();
            // Optional mission generated for the order's driver when entering this status (retour / echange).
            $table->string('creates_mission_type', 30)->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach (self::DEFAULT_STATUSES as $i => $row) {
            DB::table('delivery_statuses')->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'category' => $row['category'],
                'color' => $row['color'],
                'icon' => $row['icon'],
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
                'required_fields' => json_encode($row['required_fields'] ?? []),
                'creates_mission_type' => $row['creates_mission_type'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Extra driver profile fields (login e-mail stays on the linked user account).
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('phone');
            $table->string('vehicle', 100)->nullable()->after('city');
            $table->text('notes')->nullable()->after('is_active');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Manual orders (WhatsApp, téléphone…) have no Shopify shop.
            $table->unsignedBigInteger('shopify_shop_id')->nullable()->change();
            $table->unsignedBigInteger('shopify_order_id')->nullable()->change();

            $table->string('source', 60)->nullable()->after('shopify_order_id');
            $table->string('carrier', 60)->nullable()->after('driver_id');
            $table->foreignId('assigned_user_id')->nullable()->after('carrier')->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('delivered_at');
            $table->index('created_at');
            $table->index('status_changed_at');
        });

        // Existing orders: the "no status" state of the old workflow becomes the configurable "À attribuer".
        DB::table('orders')->where('confirmation_status', 'confirmed')->whereNull('delivery_status')
            ->update(['delivery_status' => 'to_assign']);

        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->string('type', 30)->index();
            $table->string('status', 30)->default('a_faire')->index();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->text('items_description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('scheduled_date')->nullable()->index();
            $table->string('time_slot', 60)->nullable();
            $table->decimal('cash_amount', 10, 2)->nullable();
            $table->string('cash_direction', 20)->nullable(); // collect | remit
            $table->text('note')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');

        DB::table('orders')->where('delivery_status', 'to_assign')->update(['delivery_status' => null]);
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status_changed_at']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['source', 'carrier', 'status_changed_at']);
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['city', 'vehicle', 'notes']);
        });

        Schema::dropIfExists('delivery_statuses');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('settings');
    }
};
