<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLootStat extends Model
{
    protected $fillable = [
        'user_id',
        'total_drops',
        'legendary_count',
        'last_drop_at',
    ];

    protected function casts(): array
    {
        return [
            'total_drops' => 'integer',
            'legendary_count' => 'integer',
            'last_drop_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
