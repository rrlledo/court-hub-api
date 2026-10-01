<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method')->default('qr');
            $table->dateTime('checked_in_at');
            $table->timestamps();
            $table->index(['tenant_id', 'membership_id', 'checked_in_at'], 'membership_check_ins_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_check_ins');
    }
};
