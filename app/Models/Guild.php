<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guild extends Model
{
    protected $fillable = [
        'name',
        'description',
        'created_by',
        'treasury_balance',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'treasury_balance' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(GuildInvite::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GuildEvent::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'joined_at', 'contributed_gold')
            ->using(GuildMember::class);
    }
}
