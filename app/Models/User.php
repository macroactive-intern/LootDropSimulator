<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function droppedItems(): HasMany
    {
        return $this->hasMany(DroppedItem::class);
    }

    public function lootStat(): HasOne
    {
        return $this->hasOne(UserLootStat::class);
    }

    public function createdGuilds(): HasMany
    {
        return $this->hasMany(Guild::class, 'created_by');
    }

    public function sentGuildInvites(): HasMany
    {
        return $this->hasMany(GuildInvite::class, 'invited_by');
    }

    public function guildEventsActed(): HasMany
    {
        return $this->hasMany(GuildEvent::class, 'actor_id');
    }

    public function guildEventsTargeted(): HasMany
    {
        return $this->hasMany(GuildEvent::class, 'target_id');
    }

    public function guilds(): BelongsToMany
    {
        return $this->belongsToMany(Guild::class)
            ->withPivot('role', 'joined_at', 'contributed_gold')
            ->using(GuildMember::class);
    }
}
