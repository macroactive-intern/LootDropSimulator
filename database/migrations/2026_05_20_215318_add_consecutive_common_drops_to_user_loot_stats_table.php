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
        Schema::table('user_loot_stats', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_common_drops')->default(0)->after('legendary_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_loot_stats', function (Blueprint $table) {
            $table->dropColumn('consecutive_common_drops');
        });
    }
};
