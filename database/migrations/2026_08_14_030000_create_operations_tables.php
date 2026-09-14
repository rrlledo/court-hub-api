<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('billing_period');
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('PHP');
            $table->unsignedInteger('duration_days');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status'], 'memberships_tenant_user_status_idx');
        });
        Schema::create('coach_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('bio')->nullable();
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('quantity_total');
            $table->unsignedInteger('quantity_available');
            $table->decimal('rental_price', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name'], 'inventory_branch_name_uq');
        });
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->dateTime('rented_at');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('status')->default('active');
            $table->decimal('amount', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('format')->default('singles');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->decimal('entry_fee', 10, 2)->default(0);
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });
        Schema::create('tournament_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('registered');
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id'], 'tourn_reg_tourn_user_uq');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique('payments_reference_uq');
            $table->string('method');
            $table->string('status')->default('paid');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('PHP');
            $table->json('provider_payload')->nullable();
            $table->timestamps();
        });
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method')->default('front-desk');
            $table->dateTime('checked_in_at');
            $table->timestamps();
            $table->unique(['booking_id'], 'check_ins_booking_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('tournament_registrations');
        Schema::dropIfExists('tournaments');
        Schema::dropIfExists('rentals');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('coach_profiles');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
    }
};
