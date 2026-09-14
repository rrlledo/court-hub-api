<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique('bookings_reference_uq');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('reserved');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('PHP');
            $table->dateTime('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['court_id', 'starts_at', 'ends_at'], 'bookings_court_time_idx');
            $table->index(['tenant_id', 'status'], 'bookings_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
