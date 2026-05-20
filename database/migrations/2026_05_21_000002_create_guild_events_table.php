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
        Schema::create('guild_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('event_type', [
                'join',
                'leave',
                'kick',
                'promote',
                'demote',
                'deposit',
                'withdraw',
                'invite_sent',
                'invite_accepted',
            ])->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['guild_id', 'event_type']);
            $table->index(['guild_id', 'created_at']);
            $table->index('actor_id');
            $table->index('target_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guild_events');
    }
};
