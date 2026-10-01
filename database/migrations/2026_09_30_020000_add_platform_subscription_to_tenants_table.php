<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('subscription_plan')->default('starter')->after('is_active');
            $table->string('subscription_status')->default('trial')->after('subscription_plan');
            $table->decimal('subscription_amount', 10, 2)->default(0)->after('subscription_status');
            $table->timestamp('subscription_renews_at')->nullable()->after('subscription_amount');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['subscription_plan', 'subscription_status', 'subscription_amount', 'subscription_renews_at']);
        });
    }
};
