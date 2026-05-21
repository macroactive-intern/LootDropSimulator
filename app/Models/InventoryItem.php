<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'user_id',
        'item_id',
        'quantity',
        'is_tradable',
        'is_in_escrow',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'is_tradable' => 'boolean',
            'is_in_escrow' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function tradeItems(): HasMany
    {
        return $this->hasMany(TradeItem::class);
    }

    public function escrowItems(): HasMany
    {
        return $this->hasMany(EscrowItem::class);
    }
}
