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
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->string('sender'); 
            $table->string('receiver');
            $table->string('subject')->nullable();
            $table->longText('html_body'); // Full HTML store karne ke liye longText
            $table->longText('text_body')->nullable(); // Plain text version (optional)
            $table->string('message_id')->unique()->nullable(); // IMAP/Mailgun tracking ke liye
            $table->enum('type', ['sent', 'received'])->default('sent');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
