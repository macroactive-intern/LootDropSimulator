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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiator_id')->constrained('users');
            $table->foreignId('recipient_id')->constrained('users');
            $table->foreignId('guild_id')->constrained('guilds');
            $table->enum('status', [
                'pending',
                'accepted',
                'rejected',
                'completed',
                'expired',
                'cancelled',
            ])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('initiator_id');
            $table->index('recipient_id');
            $table->index('guild_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
