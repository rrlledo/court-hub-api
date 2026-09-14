<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->string('source')->default('online')->after('status');
            $t->string('qr_code')->nullable()->unique('bookings_qr_code_uq')->after('reference');
            $t->foreignId('parent_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
        });
        Schema::create('waitlists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('court_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('status')->default('waiting');
            $t->timestamps();
        });
        Schema::create('recurring_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('court_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('day_of_week');
            $t->time('starts_at');
            $t->unsignedInteger('duration_minutes');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 10, 2);
            $t->string('status')->default('requested');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('recurring_reservations');
        Schema::dropIfExists('waitlists');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_booking_id'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['source', 'qr_code']));
    }
};
