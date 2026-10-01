<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', fn (Blueprint $table) => $table->boolean('registration_open')->default(false));
        Schema::table('users', fn (Blueprint $table) => $table->foreignId('home_facility_id')->nullable()->constrained('facilities')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('home_facility_id'));
        Schema::table('facilities', fn (Blueprint $table) => $table->dropColumn('registration_open'));
    }
};
