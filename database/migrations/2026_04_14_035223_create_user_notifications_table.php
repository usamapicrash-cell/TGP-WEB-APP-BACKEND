<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('msg');
            $table->string('type')->default('lead'); 
            
            // Recipient
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Sender (0 = System)
            $table->unsignedBigInteger('from_user_id')->default(0); 

            $table->timestamp('read_at')->nullable(); 
            $table->timestamps(); 
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_notifications');
    }
};