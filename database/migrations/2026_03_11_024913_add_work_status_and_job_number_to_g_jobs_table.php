<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('gjobs', function (Blueprint $table) {
            $table->string('work_status')->default('pending')->after('status');
            $table->string('job_number')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
   public function down(): void
    {
        Schema::table('gjobs', function (Blueprint $table) {
            $table->dropColumn(['work_status','job_number']);
        });
    }
};
