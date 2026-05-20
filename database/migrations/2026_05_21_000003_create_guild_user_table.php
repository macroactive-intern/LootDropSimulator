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
        Schema::create('guild_user', function (Blueprint $table) {
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', [
                'leader',
                'officer',
                'member',
            ])->index();
            $table->timestamp('joined_at');
            $table->unsignedBigInteger('contributed_gold')->default(0);

            $table->unique(['guild_id', 'user_id']);
            $table->index(['guild_id', 'role']);
            $table->index(['user_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guild_user');
    }
};
