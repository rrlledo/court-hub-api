<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->unique(['coach_profile_id', 'day_of_week', 'starts_at'], 'coach_avail_slot_uq');
        });
        Schema::create('coaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['coach_profile_id', 'starts_at', 'ends_at'], 'coach_sessions_time_idx');
        });
        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('damage_fee', 10, 2)->default(0)->after('deposit_amount');
            $table->timestamp('deposit_returned_at')->nullable()->after('returned_at');
        });
        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('player_one_registration_id')->nullable()->constrained('tournament_registrations')->nullOnDelete();
            $table->foreignId('player_two_registration_id')->nullable()->constrained('tournament_registrations')->nullOnDelete();
            $table->foreignId('winner_registration_id')->nullable()->constrained('tournament_registrations')->nullOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->unsignedInteger('match_number')->default(1);
            $table->dateTime('starts_at')->nullable();
            $table->string('status')->default('scheduled');
            $table->string('score')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'round_number', 'match_number'], 'tournament_match_slot_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['damage_fee', 'deposit_returned_at']);
        });
        Schema::dropIfExists('coaching_sessions');
        Schema::dropIfExists('coach_availabilities');
    }
};
