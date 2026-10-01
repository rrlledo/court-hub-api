<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->string('platform', 16);
            $table->string('device_name')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
