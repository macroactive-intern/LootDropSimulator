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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('item_id')->constrained();
            $table->rawColumn('quantity', 'integer unsigned check (quantity > 0)');
            $table->boolean('is_tradable')->default(true);
            $table->boolean('is_in_escrow')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('item_id');
            $table->index('is_in_escrow');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
