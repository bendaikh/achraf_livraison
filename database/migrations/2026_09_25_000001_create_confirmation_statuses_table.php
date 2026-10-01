<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmation_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('color', 32)->default('#64748b');
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('type', 32)->default('custom');
            $table->string('filter_label')->nullable();
            $table->boolean('show_in_filters')->default(true);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('queue_behavior', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmation_statuses');
    }
};
