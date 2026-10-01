<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });
        Schema::create('platform_subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('status')->default('open');
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        Schema::create('coach_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('revenue_share_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['coach_profile_id', 'user_id']);
        });
        Schema::create('tournament_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tournament_id', 'name']);
        });
        Schema::create('tournament_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tournament_team_id', 'user_id']);
        });
        Schema::table('tournament_registrations', function (Blueprint $table) {
            $table->foreignId('tournament_team_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn('checked_in_at');
            $table->dropConstrainedForeignId('tournament_team_id');
        });
        Schema::dropIfExists('tournament_team_members');
        Schema::dropIfExists('tournament_teams');
        Schema::dropIfExists('coach_students');
        Schema::dropIfExists('platform_subscription_invoices');
        Schema::dropIfExists('social_accounts');
    }
};
