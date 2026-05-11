<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->time('end_time')->nullable()->after('time');
            $table->string('status')->default('pending')->after('end_time'); // e.g. scheduled, completed, cancelled
            $table->text('description')->nullable()->after('status');
            $table->string('icon')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['end_time', 'status', 'description', 'icon']);
        });
    }
};
