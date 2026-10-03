<?php

use App\Services\WhatsApp\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T9 — Clients module. Clients are derived from orders (identified by normalized phone =
 * orders.phone_key); only blocks, notes and manual groups are stored. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'phone_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('phone_key', 32)->nullable()->after('phone')->index();
            });
        }

        DB::table('orders')->select('id', 'phone', 'shipping_address')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $phone = $row->phone;
                if (! $phone && $row->shipping_address) {
                    $phone = json_decode($row->shipping_address, true)['phone'] ?? null;
                }
                $key = PhoneNormalizer::digits($phone);
                DB::table('orders')->where('id', $row->id)->update(['phone_key' => $key !== '' ? $key : null]);
            }
        });

        Schema::create('client_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_key', 32)->index();
            $table->string('customer_name')->nullable();
            $table->string('reason', 120);
            $table->text('comment')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at');
            $table->foreignId('unblocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unblocked_at')->nullable();
            $table->string('unblock_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_key', 32)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('color', 20)->default('#2563eb');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('client_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_group_id')->constrained()->cascadeOnDelete();
            $table->string('phone_key', 32)->index();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_group_id', 'phone_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_group_members');
        Schema::dropIfExists('client_groups');
        Schema::dropIfExists('client_notes');
        Schema::dropIfExists('client_blocks');
        // orders.phone_key kept on purpose (additive, harmless).
    }
};
