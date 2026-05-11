<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Enum use karna best hai taaki in do values ke ilawa kuch save na ho
            $table->enum('type', ['site_visit', 'appointment'])->default('appointment')->after('title');
        });
    }

    public function down()
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
