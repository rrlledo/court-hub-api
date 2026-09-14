<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sport')->default('pickleball');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'court_types_tenant_name_uq');
        });
        Schema::create('operating_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['branch_id', 'day_of_week'], 'operating_hours_branch_day_uq');
        });
        Schema::create('branch_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name');
            $table->boolean('is_closed')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'holiday_date'], 'branch_holidays_branch_date_uq');
        });
        Schema::create('court_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status')->default('scheduled');
            $table->text('reason');
            $table->timestamps();
        });
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('court_maintenance');
        Schema::dropIfExists('branch_holidays');
        Schema::dropIfExists('operating_hours');
        Schema::dropIfExists('court_types');
    }
};
