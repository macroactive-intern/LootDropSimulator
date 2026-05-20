<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class GuildUser extends Pivot
{
    public $timestamps = false;

    protected $table = 'guild_user';

    protected $fillable = [
        'guild_id',
        'user_id',
        'role',
        'joined_at',
        'contributed_gold',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'contributed_gold' => 'integer',
        ];
    }
}
