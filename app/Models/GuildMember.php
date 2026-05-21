<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class GuildMember extends Pivot
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'guild_user';

    protected $foreignKey = 'guild_id';

    protected $relatedKey = 'user_id';

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

    public function guild(): BelongsTo
    {
        return $this->belongsTo(Guild::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
