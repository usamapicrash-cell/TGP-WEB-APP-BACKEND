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
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_uuid')->nullable()->index();
            $table->string('phone_number');
            $table->string('client_name')->nullable(); // resolved from Leads at log time
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('status')->default('started'); // ringing/answered/completed/no-answer/busy/failed...
            $table->unsignedInteger('duration')->default(0); // seconds
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // agent
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
