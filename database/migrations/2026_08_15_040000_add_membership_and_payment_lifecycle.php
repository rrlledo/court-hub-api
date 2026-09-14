<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->string('plan_type')->default('standard')->after('name');
            $table->boolean('priority_booking')->default(false)->after('discount_percent');
            $table->unsignedInteger('session_count')->nullable()->after('duration_days');
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->timestamp('frozen_at')->nullable()->after('auto_renew');
            $table->unsignedInteger('remaining_sessions')->nullable()->after('frozen_at');
        });
        Schema::create('membership_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->string('card_number')->unique('membership_cards_number_uq');
            $table->string('qr_code')->unique('membership_cards_qr_uq');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('membership_session_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
            $table->string('provider')->default('manual')->after('method');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->string('invoice_number')->nullable()->unique('payments_invoice_number_uq')->after('reference');
            $table->index(['tenant_id', 'provider', 'provider_reference'], 'payments_provider_ref_idx');
        });
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id')->unique('payment_webhook_event_uq');
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('requested');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_webhook_events');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_provider_ref_idx');
            $table->dropConstrainedForeignId('membership_id');
            $table->dropColumn(['provider', 'provider_reference', 'paid_at', 'invoice_number']);
        });
        Schema::dropIfExists('membership_session_usages');
        Schema::dropIfExists('membership_cards');
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['auto_renew', 'frozen_at', 'remaining_sessions']);
        });
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn(['plan_type', 'priority_booking', 'session_count']);
        });
    }
};
