<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gjobs', function (Blueprint $table) {
            // 1. Add lead_id as a foreign key if it doesn't exist
            if (!Schema::hasColumn('gjobs', 'lead_id')) {
                $table->foreignId('lead_id')
                      ->after('id') // Position it at the start
                      ->nullable() 
                      ->constrained('leads')
                      ->onDelete('cascade');
            }

            // 2. Ensure status column exists and defaults to 'lead'
            // If the column exists, we modify it; otherwise, we create it.
            if (Schema::hasColumn('gjobs', 'status')) {
                $table->string('status')->default('lead')->change();
            } else {
                $table->string('status')->default('lead')->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gjobs', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn('lead_id');
            // We usually don't drop 'status' as it might be a core column
        });
    }
};