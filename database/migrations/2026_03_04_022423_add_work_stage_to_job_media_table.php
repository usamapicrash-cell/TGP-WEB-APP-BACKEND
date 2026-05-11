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
        Schema::table('job_media', function (Blueprint $table) {
            // Naya column add kar rahe hain jo null bhi ho sakta hai
            $table->string('work_stage')->nullable()->after('type'); // before, during, after
        });
    }

    public function down()
    {
        Schema::table('job_media', function (Blueprint $table) {
            $table->dropColumn('work_stage');
        });
    }
};
