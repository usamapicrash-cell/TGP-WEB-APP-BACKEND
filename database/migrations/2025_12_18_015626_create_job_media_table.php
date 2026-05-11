<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_media', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gjob_id')
                ->constrained()
                ->cascadeOnDelete();

            // 👤 Who uploaded (admin / glazier)
            $table->foreignId('created_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('type', ['image', 'canvas', 'document']);
            $table->string('file_path');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_media');
    }
};
