<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            // column add kar rahe hain, normally role_id ke baad ya last mein
            $blueprint->unsignedBigInteger('created_by')->nullable()->after('role_id');
            
            // Optional: Foreign key constraint (agar aap chaho)
            $blueprint->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            // rollback ke liye foreign key aur column drop karenge
            $blueprint->dropForeign(['created_by']);
            $blueprint->dropColumn('created_by');
        });
    }
};