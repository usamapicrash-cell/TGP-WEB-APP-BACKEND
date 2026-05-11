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
        Schema::table('purchase_orders', function (Blueprint $col) {
            // Hum 'json' use kar rahe hain kyunki hum multiple paths save karenge
            $col->json('attachments')->nullable()->after('drawing_data');
            
            // Just in case agar aapka 'drawing_data' column pehle se nahi hai:
            if (!Schema::hasColumn('purchase_orders', 'drawing_data')) {
                $col->json('drawing_data')->nullable()->after('total');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $col) {
            $col->dropColumn(['attachments', 'drawing_data']);
        });
    }
};