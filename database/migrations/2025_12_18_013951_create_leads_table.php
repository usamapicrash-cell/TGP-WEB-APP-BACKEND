<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Who created the lead (Admin / Executive)
            $table->foreignId('created_by')
                ->constrained('users')
                ->onDelete('cascade');

            $table->string('client_name');
            $table->foreignId('type')
                ->nullable()
                ->constrained('lead_types')
                ->nullOnDelete();            
            $table->string('source')->nullable();
            $table->string('status')->default('lead');
            $table->decimal('value', 10, 2)->nullable();
            $table->date('date')->nullable();
            $table->string('company')->nullable();
            $table->string('address')->nullable();
            $table->string('job_address')->nullable();
            $table->string('phone');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};


