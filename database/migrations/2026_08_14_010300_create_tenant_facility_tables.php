<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique('tenants_slug_uq');
            $table->string('timezone')->default('Asia/Manila');
            $table->char('country_code', 2)->default('PH');
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone')->default('Asia/Manila');
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone')->default('Asia/Manila');
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sport')->default('pickleball');
            $table->string('status')->default('active');
            $table->decimal('base_price', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'name'], 'courts_branch_name_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courts');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('organizations');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        Schema::dropIfExists('tenants');
    }
};
