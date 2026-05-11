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
        Schema::create('gjobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('glazier_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->enum('status', [
                'scheduled',
                'in_progress',
                'on_hold',
                'completed',
                'cancelled'
            ])->default('scheduled');

            $table->unsignedTinyInteger('progress')->default(0); // %

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
